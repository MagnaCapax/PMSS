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
            $path = $this->tempDir.'/alice/.lighttpd/access.log';
            $this->pmssWriteFile($path, str_repeat('x', $bytes));

            $this->pmssAssertArraySubsetSame($expected, \pmssLighttpdAccessLogTrimFile($path, $threshold));
            $this->assertEquals($finalSize, filesize($path));
        }
    }

    public function testTrimFileRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real-access.log';
        $linkPath = $this->tempDir.'/alice/.lighttpd/access.log';
        @mkdir(dirname($linkPath), 0755, true);
        file_put_contents($realPath, str_repeat('x', 128));
        symlink($realPath, $linkPath);

        $result = \pmssLighttpdAccessLogTrimFile($linkPath, 64);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'unsafe_target'], $result);
        $this->assertEquals(128, filesize($realPath));
    }

    public function testTrimFileRejectsMultipleLinks(): void
    {
        $path = $this->tempDir.'/alice/.lighttpd/access.log';
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
        $path = $this->tempDir.'/alice/.lighttpd/access.log';
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
        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'lock_busy'], $results['lock-false-exception'][0]);
        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'path_changed'], $results['identity-false-exception'][0]);
        $this->pmssAssertArraySubsetSame(['status' => 'error', 'reason' => 'truncate_failed'], $results['truncate-false-exception'][0]);
        $this->pmssAssertArraySubsetSame(['status' => 'error', 'reason' => 'flush_failed'], $results['flush-false-exception'][0]);
        $this->pmssAssertArraySubsetSame(['status' => 'error', 'reason' => 'close_failed'], $results['close-false-exception'][0]);
        $this->pmssAssertArraySubsetSame(['status' => 'trimmed', 'sizeBefore' => 128, 'sizeAfter' => 0], $results['normal-exception'][0]);
    }

    public function testTrimFileRejectsRelativePath(): void
    {
        $result = \pmssLighttpdAccessLogTrimFile('access.log', 64);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'unsafe_target'], $result);
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
