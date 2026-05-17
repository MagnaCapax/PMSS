<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/accessLog.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdAccessLogTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-lighttpd-access-log-');
    }

    public function testThresholdMatchesOneHundredMiB(): void
    {
        $this->assertEquals(100 * 1024 * 1024, \PMSS_LIGHTTPD_ACCESS_LOG_THRESHOLD_BYTES);
    }

    public function testTrimFileTruncatesOversizedRegularFile(): void
    {
        $path = $this->tempDir.'/alice/.lighttpd/access.log';
        $this->pmssWriteFile($path, str_repeat('x', 128));

        $result = \pmssLighttpdAccessLogTrimFile($path, 64);

        $this->assertEquals('trimmed', $result['status']);
        $this->assertEquals(128, $result['sizeBefore']);
        $this->assertEquals(0, filesize($path));
    }

    public function testTrimFileSkipsLogsBelowThreshold(): void
    {
        $path = $this->tempDir.'/alice/.lighttpd/access.log';
        $this->pmssWriteFile($path, str_repeat('x', 16));

        $result = \pmssLighttpdAccessLogTrimFile($path, 64);

        $this->assertEquals('skip', $result['status']);
        $this->assertEquals('below_threshold', $result['reason']);
        $this->assertEquals(16, filesize($path));
    }

    public function testTrimFileRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real-access.log';
        $linkPath = $this->tempDir.'/alice/.lighttpd/access.log';
        @mkdir(dirname($linkPath), 0755, true);
        file_put_contents($realPath, str_repeat('x', 128));
        symlink($realPath, $linkPath);

        $result = \pmssLighttpdAccessLogTrimFile($linkPath, 64);

        $this->assertEquals('skip', $result['status']);
        $this->assertEquals('unsafe_target', $result['reason']);
        $this->assertEquals(128, filesize($realPath));
    }

    public function testTrimFileRejectsMultipleLinks(): void
    {
        $path = $this->tempDir.'/alice/.lighttpd/access.log';
        $linkedPath = $this->tempDir.'/linked-access.log';
        $this->pmssWriteFile($path, str_repeat('x', 128));
        link($path, $linkedPath);

        $result = \pmssLighttpdAccessLogTrimFile($path, 64);

        $this->assertEquals('skip', $result['status']);
        $this->assertEquals('multiple_links', $result['reason']);
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

        $this->assertEquals('skip', $result['status']);
        $this->assertEquals('lock_busy', $result['reason']);
        $this->assertEquals(128, filesize($path));
    }

    public function testTrimFileRejectsRelativePath(): void
    {
        $result = \pmssLighttpdAccessLogTrimFile('access.log', 64);

        $this->assertEquals('skip', $result['status']);
        $this->assertEquals('unsafe_target', $result['reason']);
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
        $src = $this->pmssReadRepoFile('scripts/cron/lighttpdAccessLogTrim.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/lighttpd/accessLog.php';", $src);
        $this->assertStringContainsString('pmssLighttpdAccessLogTrimFile($logPath, $thresholdBytes);', $src);
        $this->assertStringContainsString('Trimmed oversized lighttpd access log for', $src);
    }
}
