<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

class UserConfigRuntimeTest extends TestCase
{
    public function testRtorrentLockPidParsesCanonicalLock(): void
    {
        $lockFile = $this->pmssMakeTempFile('pmss-rtorrent-lock-');
        file_put_contents($lockFile, "12345:+session\n");

        $this->assertSame(12345, \pmssUserConfigRtorrentLockPid($lockFile));
    }

    public function testRtorrentLockPidRejectsMalformedValues(): void
    {
        foreach (['', '0:+session', '1:+session', 'abc:+session', '-5:+session'] as $content) {
            $lockFile = $this->pmssMakeTempFile('pmss-rtorrent-lock-');
            file_put_contents($lockFile, $content);

            $this->assertSame(null, \pmssUserConfigRtorrentLockPid($lockFile));
        }
    }

    public function testRtorrentLockPidRejectsSymlink(): void
    {
        $target = $this->pmssMakeTempFile('pmss-rtorrent-lock-target-');
        file_put_contents($target, "12345:+session\n");
        $link = $this->pmssMakeTempPath('pmss-rtorrent-lock-link-');
        symlink($target, $link);

        $this->assertSame(null, \pmssUserConfigRtorrentLockPid($link));
    }

    public function testProcStatusUidParsesRealUid(): void
    {
        $procRoot = $this->pmssMakeTempDir('pmss-proc-');
        mkdir($procRoot.'/12345');
        file_put_contents($procRoot.'/12345/status', "Name:\trtorrent\nUid:\t1500\t1500\t1500\t1500\n");

        $this->assertSame(1500, \pmssUserConfigProcStatusUid(12345, $procRoot));
    }

    public function testRtorrentProcessOwnedByAcceptsMatchingRtorrent(): void
    {
        $procRoot = $this->pmssMakeTempDir('pmss-proc-');
        mkdir($procRoot.'/12345');
        file_put_contents($procRoot.'/12345/status', "Name:\trtorrent\nUid:\t1500\t1500\t1500\t1500\n");
        file_put_contents($procRoot.'/12345/comm', "rtorrent\n");

        $this->assertTrue(\pmssUserConfigRtorrentProcessOwnedBy(12345, 1500, $procRoot));
    }

    public function testRtorrentProcessOwnedByRejectsWrongUidOrCommand(): void
    {
        $procRoot = $this->pmssMakeTempDir('pmss-proc-');
        mkdir($procRoot.'/12345');
        file_put_contents($procRoot.'/12345/status', "Name:\tbash\nUid:\t1500\t1500\t1500\t1500\n");
        file_put_contents($procRoot.'/12345/comm', "bash\n");

        $this->assertFalse(\pmssUserConfigRtorrentProcessOwnedBy(12345, 1500, $procRoot));
        $this->assertFalse(\pmssUserConfigRtorrentProcessOwnedBy(12345, 1600, $procRoot));
    }
}
