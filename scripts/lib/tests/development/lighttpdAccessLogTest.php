<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/accessLog.php';

class LighttpdAccessLogTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-lighttpd-access-log-']; }

    public function testThresholdMatchesOneHundredMiB(): void
    {
        $this->assertEquals(100 * 1024 * 1024, \PMSS_LIGHTTPD_ACCESS_LOG_THRESHOLD_BYTES);
    }

    public function testTrimFileHandlesRegularFilesBySize(): void
    {
        foreach ([
            [128, 64, ['status' => 'trimmed', 'sizeBefore' => 128], 0],
            [16, 64, ['status' => 'skip', 'reason' => 'below_threshold'], 16],
        ] as [$bytes, $threshold, $expected, $finalSize]) {
            $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
            $this->pmssWriteFile($path, str_repeat('x', $bytes));

            $this->pmssAssertArraySubsetSame($expected, \pmssLighttpdAccessLogTrimFile($path, $threshold));
            $this->assertEquals($finalSize, filesize($path));
        }
    }

    public function testTrimFileRejectsSymlinkTarget(): void
    {
        $realPath = $this->pmssWriteFile($this->tempDir.'/real-access.log', str_repeat('x', 128));
        $linkPath = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $this->pmssWriteSymlinks($this->tempDir, ['fixtur01/.lighttpd/access.log' => $realPath]);

        $result = \pmssLighttpdAccessLogTrimFile($linkPath, 64);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'unsafe_target'], $result);
        $this->assertEquals(128, filesize($realPath));
    }

    public function testTrimFileRejectsMultipleLinks(): void
    {
        $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $linkedPath = $this->tempDir.'/linked-access.log';
        $this->pmssWriteFile($path, str_repeat('x', 128));
        link($path, $linkedPath);

        $result = \pmssLighttpdAccessLogTrimFile($path, 64);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'multiple_links'], $result);
        $this->assertEquals(128, filesize($path));
        $this->assertEquals(128, filesize($linkedPath));
    }

    public function testTrimFileSkipsBusyLog(): void
    {
        $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $this->pmssWriteFile($path, str_repeat('x', 128));

        $lockHandle = fopen($path, 'c+');
        $this->assertTrue(is_resource($lockHandle));
        $this->assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        $result = \pmssLighttpdAccessLogTrimFile($path, 64);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'lock_busy'], $result);
        $this->assertEquals(128, filesize($path));
    }

    public function testTrimFileClosesHandleAndReportsIoFailures(): void
    {
        // Namespace shims expose stream failures without touching the runner's handles.
        $script = <<<'PHP'
namespace LighttpdAccessLogTrimFixture;
function fault($stage) {
    if ($GLOBALS['stage'] === $stage && $GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
}
function pmssUserFilePathIsSafe($path) { return true; }
function pmssRegularFilePathIsReadable($path) { return true; }
function fopen($path, $mode) {
    return $GLOBALS['handle'] = $GLOBALS['stage'] === 'open-false' ? false : \fopen($path, $mode);
}
function flock($handle, $operation) {
    fault('lock-throw');
    return $GLOBALS['stage'] === 'lock-false' ? false : \flock($handle, $operation);
}
function pmssLockFileHandleMatchesPath($handle, $path, $pathStat = null, &$handleStat = null) {
    fault('identity-throw');
    if ($GLOBALS['stage'] === 'identity-false') return false;
    return \pmssLockFileHandleMatchesPath($handle, $path, $pathStat, $handleStat);
}
function ftruncate($handle, $size) {
    fault('truncate-throw');
    return $GLOBALS['stage'] === 'truncate-false' ? false : \ftruncate($handle, $size);
}
function fflush($handle) {
    fault('flush-throw');
    return $GLOBALS['stage'] === 'flush-false' ? false : \fflush($handle);
}
function fclose($handle) {
    ++$GLOBALS['closeCalls'];
    $closed = \fclose($handle);
    fault('close-throw');
    return $GLOBALS['stage'] === 'close-false' ? false : $closed;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace(
            'scripts/lib/lighttpd/accessLog.php',
            'LighttpdAccessLogTrimFixture'
        );
        $script .= <<<'PHP'
$path = getenv('PMSS_TEST_ACCESS_LOG_PATH');
$results = [];
foreach (['open-false', 'lock-false', 'identity-false', 'truncate-false', 'flush-false',
    'close-false', 'normal', 'lock-throw', 'identity-throw', 'truncate-throw',
    'flush-throw', 'close-throw'] as $stage) {
    foreach (['exception', 'error'] as $kind) {
        \file_put_contents($path, str_repeat('x', 128));
        $GLOBALS['stage'] = $stage;
        $GLOBALS['failure'] = substr($stage, -6) === '-throw'
            ? ($kind === 'exception' ? new \RuntimeException('stream failure') : new \Error('stream failure'))
            : null;
        $GLOBALS['closeCalls'] = 0;
        $GLOBALS['handle'] = null;
        $caught = null;
        $result = null;
        try {
            $result = pmssLighttpdAccessLogTrimFile($path, 64);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $results[$stage.'-'.$kind] = [
            $result,
            $GLOBALS['failure'] !== null && $caught === $GLOBALS['failure'],
            $GLOBALS['closeCalls'],
            !is_resource($GLOBALS['handle']),
        ];
    }
}
echo json_encode($results);
PHP;

        $results = $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_ACCESS_LOG_PATH' => $this->pmssMakeTempPath('pmss-lighttpd-access-log-io-'),
        ]);
        foreach ($results as $case => $result) {
            $throws = strpos($case, '-throw-') !== false;
            $openFails = strpos($case, 'open-false-') === 0;
            $this->assertSame($throws, $result[1], $case.' throwable');
            $this->assertSame($openFails ? 0 : 1, $result[2], $case.' close count');
            $this->assertTrue($result[3], $case.' handle closed');
        }
        foreach ([
            'lock-false-exception' => ['status' => 'skip', 'reason' => 'lock_busy'],
            'identity-false-exception' => ['status' => 'skip', 'reason' => 'path_changed'],
            'truncate-false-exception' => ['status' => 'error', 'reason' => 'truncate_failed'],
            'flush-false-exception' => ['status' => 'error', 'reason' => 'flush_failed'],
            'close-false-exception' => ['status' => 'error', 'reason' => 'close_failed'],
            'normal-exception' => ['status' => 'trimmed', 'sizeBefore' => 128, 'sizeAfter' => 0],
        ] as $case => $expected) $this->pmssAssertArraySubsetSame($expected, $results[$case][0], $case.': ');
    }

    public function testTrimFileRejectsRelativePath(): void
    {
        $result = \pmssLighttpdAccessLogTrimFile('access.log', 64);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'unsafe_target'], $result);
    }

    /** Entry stamped 2026-01-01 00:00:00 UTC (epoch 1767225600) in lighttpd's default access-log format. */
    private function pmssAccessLogLine(string $stamp = '01/Jan/2026:00:00:00 +0000'): string
    {
        return '203.0.113.7 example.invalid user1 ['.$stamp.'] "GET /user-user1/ HTTP/1.1" 200 512 "-" "agent"'."\n";
    }

    public function testRetentionCapIsThirtyDays(): void
    {
        $this->assertEquals(30 * 86400, \PMSS_LIGHTTPD_ACCESS_LOG_MAX_AGE_SECONDS);
    }

    public function testTrimFileUsesTheOldestEntryNotTheMtimeForTheAgeCap(): void
    {
        // A log that is still appended to always has a fresh mtime; its first line is what bounds retention.
        $cap = 30 * 86400;
        foreach ([
            // [first-line stamp, now, expected status, final size]
            ['01/Jan/2026:00:00:00 +0000', 1767225600 + $cap, 'skip', null],
            ['01/Jan/2026:00:00:00 +0000', 1767225600 + $cap + 1, 'trimmed', 0],
            ['01/Jan/2026:02:00:00 +0200', 1767225600 + $cap + 1, 'trimmed', 0],
            ['01/Jan/2026:02:00:00 +0200', 1767225600 + $cap, 'skip', null],
        ] as [$stamp, $now, $status, $finalSize]) {
            $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
            $this->pmssWriteFile($path, $this->pmssAccessLogLine($stamp).$this->pmssAccessLogLine('20/Feb/2026:00:00:00 +0000'));
            clearstatcache();
            $size = filesize($path);

            $result = \pmssLighttpdAccessLogTrimFile($path, 1024 * 1024, $cap, $now);

            $this->assertSame($status, $result['status'], $stamp.' at '.$now);
            $this->assertSame($finalSize ?? $size, filesize($path), $stamp.' at '.$now);
            if ($status === 'trimmed') $this->pmssAssertArraySubsetSame(['reason' => 'age', 'sizeBefore' => $size], $result);
        }
    }

    public function testTrimFileFallsBackToMtimeWhenTheFirstLineHasNoTimestamp(): void
    {
        $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $this->pmssWriteFile($path, "custom format line without a stamp\n");
        touch($path, time() - 40 * 86400);
        $this->pmssAssertArraySubsetSame(['status' => 'trimmed', 'reason' => 'age'], \pmssLighttpdAccessLogTrimFile($path, 1024 * 1024));
        clearstatcache();
        $this->assertEquals(0, filesize($path));

        $this->pmssWriteFile($path, "custom format line without a stamp\n");
        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'below_threshold'], \pmssLighttpdAccessLogTrimFile($path, 1024 * 1024));
        clearstatcache();
        $this->assertTrue(filesize($path) > 0);
    }

    public function testAgeCapDisabledEmptyLogAndSizeCapKeepTheirOwnBehaviour(): void
    {
        $path = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $this->pmssWriteFile($path, $this->pmssAccessLogLine());
        // A null cap turns the age rule off; the oldest entry alone never trims.
        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'below_threshold'], \pmssLighttpdAccessLogTrimFile($path, 1024 * 1024, null, 1767225600 + 400 * 86400));
        // An empty log has nothing to age, whatever its mtime.
        $this->pmssWriteFile($path, '');
        touch($path, 1000);
        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'below_threshold'], \pmssLighttpdAccessLogTrimFile($path, 1024 * 1024));
        // Over the size cap the trim is a size trim, with no age reason.
        $this->pmssWriteFile($path, $this->pmssAccessLogLine().str_repeat('x', 128));
        $result = \pmssLighttpdAccessLogTrimFile($path, 64);
        $this->assertSame('trimmed', $result['status']);
        $this->assertFalse(isset($result['reason']));
    }

    public function testAgeCapStillRefusesSymlinkedLog(): void
    {
        // The issue's secondary finding: an old log behind a symlink is skipped, never truncated through the link.
        $realPath = $this->pmssWriteFile($this->tempDir.'/real-access.log', $this->pmssAccessLogLine());
        $linkPath = $this->tempDir.'/fixtur01/.lighttpd/access.log';
        $this->pmssWriteSymlinks($this->tempDir, ['fixtur01/.lighttpd/access.log' => $realPath]);

        $result = \pmssLighttpdAccessLogTrimFile($linkPath, 1024 * 1024, 86400, 1767225600 + 400 * 86400);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'unsafe_target'], $result);
        $this->assertTrue(filesize($realPath) > 0);
    }

    public function testCronWiringSchedulesAndUsesSharedTrimHelper(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '17 * * * *   root    /scripts/cron/lighttpdAccessLogTrim.php >> /var/log/pmss/lighttpdAccessLogTrim.log 2>&1',
            'root.cron should schedule lighttpd access log trimming hourly'
        );

        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/lighttpdAccessLogTrim.php', [
            "require_once __DIR__.'/../lib/lighttpd/accessLog.php';",
            'pmssLighttpdAccessLogTrimFile($logPath, $thresholdBytes);',
            'Trimmed oversized lighttpd access log for',
        ]);
    }
}
