<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/traffic.php';

class TorrentThrottleTest extends TestCase
{
    private $homeRoot;
    private $user;

    public function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-throttle-');
        $this->user = 'alice';
        $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
    }

    private function throttlePath(): string
    {
        return $this->homeRoot.'/'.$this->user.'/.torrentThrottle';
    }

    private function writeThrottleFile(string $content, int $mode = 0640): void
    {
        $path = $this->throttlePath();
        file_put_contents($path, $content);
        chmod($path, $mode);
    }

    public function testReadReturnsNullWhenMissing(): void
    {
        $this->assertEquals(null, pmssReadTorrentThrottle($this->user));
    }

    public function testReadReturnsNullForInvalidContent(): void
    {
        $this->writeThrottleFile('nope');
        $this->assertEquals(null, pmssReadTorrentThrottle($this->user));
    }

    public function testReadReturnsZeroForZeroValue(): void
    {
        $this->writeThrottleFile('0');
        $this->assertEquals(0, pmssReadTorrentThrottle($this->user));
    }

    public function testReadReturnsValueForPositive(): void
    {
        $this->writeThrottleFile('123');
        $this->assertEquals(123, pmssReadTorrentThrottle($this->user));
    }

    public function testReadRejectsGroupWritable(): void
    {
        $this->writeThrottleFile('10', 0666);
        $this->assertEquals(null, pmssReadTorrentThrottle($this->user));
    }

    public function testReadRejectsInvalidUsernameBeforePathResolution(): void
    {
        $this->pmssEnsureDir($this->homeRoot.'/alice/evil');
        file_put_contents($this->homeRoot.'/alice/evil/.torrentThrottle', '77');

        $this->assertEquals(null, pmssReadTorrentThrottle('alice/evil'));
    }

    public function testWriteCreatesFileForPositive(): void
    {
        $result = pmssWriteTorrentThrottle($this->user, 55);
        $this->assertTrue($result, 'Expected write to succeed');
        $this->assertTrue(is_file($this->throttlePath()), 'Throttle file not created');
        $this->assertEquals('55', trim((string) file_get_contents($this->throttlePath())));
    }

    public function testWriteRemovesFileForZero(): void
    {
        $this->writeThrottleFile('25');
        $result = pmssWriteTorrentThrottle($this->user, 0);
        $this->assertTrue($result, 'Expected removal to succeed');
        $this->assertTrue(!is_file($this->throttlePath()), 'Throttle file was not removed');
    }

    public function testWriteRejectsMissingHome(): void
    {
        $this->assertTrue(!pmssWriteTorrentThrottle('missing', 10), 'Expected write to fail without home dir');
    }

    public function testWriteRejectsSymlink(): void
    {
        $path = $this->throttlePath();
        file_put_contents($this->homeRoot.'/'.$this->user.'/target', '1');
        symlink($this->homeRoot.'/'.$this->user.'/target', $path);
        $this->assertTrue(!pmssWriteTorrentThrottle($this->user, 10), 'Expected write to fail on symlink');
    }

    public function testWriteRejectsDirectoryWhenRemovingThrottle(): void
    {
        $path = $this->pmssEnsureDir($this->throttlePath());

        $this->assertTrue(!pmssWriteTorrentThrottle($this->user, 0), 'Expected removal to fail on directory');
        $this->assertTrue(is_dir($path), 'Throttle directory should remain untouched');
    }

    public function testWriteRejectsDirectoryWhenWritingThrottle(): void
    {
        $path = $this->pmssEnsureDir($this->throttlePath());

        $this->assertTrue(!pmssWriteTorrentThrottle($this->user, 10), 'Expected write to fail on directory');
        $this->assertTrue(is_dir($path), 'Throttle directory should remain untouched');
    }

    public function testWriteRejectsInvalidUsernameBeforePathResolution(): void
    {
        $this->pmssEnsureDir($this->homeRoot.'/alice/evil');

        $this->assertTrue(!pmssWriteTorrentThrottle('alice/evil', 10), 'Expected invalid username write to fail');
        $this->assertTrue(!is_file($this->homeRoot.'/alice/evil/.torrentThrottle'), 'Traversal-like path should remain untouched');
    }

    public function testDiskQuotaRefreshUsesSeparateGuardedCommands(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        $this->pmssResetRuntimeProfile();

        userApplyDiskQuota(['name' => $this->user, 'quota' => 10]);

        $quotaPath = $this->homeRoot.'/'.$this->user.'/.quota';
        $commands = $this->pmssProfileCommands();
        $this->assertStringContainsString(
            pmssBuildCommand('quota', ['-u', $this->user, '-s']).' > '.escapeshellarg($quotaPath),
            implode("\n", $commands)
        );
        $this->assertStringContainsString(
            pmssBuildCommand('chmod', ['o+r', $quotaPath]),
            implode("\n", $commands)
        );
        foreach ($commands as $command) {
            $this->assertStringNotContainsString(';', $command, 'Quota refresh commands must not be shell-chained');
        }
    }

    public function testDiskQuotaRefreshSkipsSymlinkedHome(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        $this->pmssResetRuntimeProfile();
        @rmdir($this->homeRoot.'/'.$this->user);
        $target = $this->pmssMakeTempDir('pmss-quota-home-target-', 0755);
        $this->pmssCreateSymlinkOrSkip($target, $this->homeRoot.'/'.$this->user);

        userApplyDiskQuota(['name' => $this->user, 'quota' => 10]);

        $commands = $this->pmssProfileCommands();
        $joined = implode("\n", $commands);
        $this->assertStringContainsString('setquota', $joined);
        $this->assertStringNotContainsString("quota '-u'", $joined);
        $this->assertStringNotContainsString('chmod', $joined);
        $this->assertStringContainsString(
            'Refreshing quota status file: unsafe or missing user home',
            (string) ($GLOBALS['PMSS_PROFILE'][1]['description'] ?? '')
        );
    }
}
