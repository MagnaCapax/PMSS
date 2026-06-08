<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/accessLog.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdAccessLogTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-lighttpd-access-log-');
    }

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

    public function testTrimFileRejectsRelativePath(): void
    {
        $result = \pmssLighttpdAccessLogTrimFile('access.log', 64);

        $this->pmssAssertArraySubsetSame(['status' => 'skip', 'reason' => 'unsafe_target'], $result);
    }

    public function testRootCronSchedulesHourlyAccessLogTrim(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '17 * * * *   root    /scripts/cron/lighttpdAccessLogTrim.php >> /var/log/pmss/lighttpdAccessLogTrim.log 2>&1',
            'root.cron should schedule lighttpd access log trimming hourly'
        );
    }

    public function testCronScriptUsesSharedTrimHelper(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/lighttpdAccessLogTrim.php', [
            "require_once __DIR__.'/../lib/lighttpd/accessLog.php';",
            'pmssLighttpdAccessLogTrimFile($logPath, $thresholdBytes);',
            'Trimmed oversized lighttpd access log for',
        ]);
    }
}
