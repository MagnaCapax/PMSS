<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

class UserConfigRuntimeTest extends TestCase
{
    private function writeRtorrentProcFixture(string $name = 'rtorrent', int $uid = 1500, string $comm = 'rtorrent'): string
    {
        $procRoot = $this->pmssMakeTempDir('pmss-proc-');
        mkdir($procRoot.'/12345');
        file_put_contents($procRoot.'/12345/status', "Name:\t{$name}\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\n");
        file_put_contents($procRoot.'/12345/comm', $comm."\n");
        return $procRoot;
    }

    private function assertRtorrentLockPid(?int $expected, string $content): void
    {
        $lockFile = $this->pmssMakeTempFile('pmss-rtorrent-lock-');
        file_put_contents($lockFile, $content);

        $this->assertSame($expected, \pmssUserConfigRtorrentLockPid($lockFile));
    }

    public function testRtorrentLockPidParsesCanonicalAndRejectsMalformedValues(): void
    {
        foreach ([
            ["12345:+session\n", 12345],
            ['', null],
            ['0:+session', null],
            ['1:+session', null],
            ['abc:+session', null],
            ['-5:+session', null],
        ] as $case) {
            $this->assertRtorrentLockPid($case[1], $case[0]);
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

    public function testRtorrentProcFixtureSupportsUidAndOwnershipChecks(): void
    {
        $procRoot = $this->writeRtorrentProcFixture();

        $this->assertSame(1500, \pmssUserConfigProcStatusUid(12345, $procRoot));
        $this->assertTrue(\pmssUserConfigRtorrentProcessOwnedBy(12345, 1500, $procRoot));
    }

    public function testRtorrentProcessOwnedByRejectsWrongUidOrCommand(): void
    {
        $procRoot = $this->writeRtorrentProcFixture('bash', 1500, 'bash');

        $this->assertFalse(\pmssUserConfigRtorrentProcessOwnedBy(12345, 1500, $procRoot));
        $this->assertFalse(\pmssUserConfigRtorrentProcessOwnedBy(12345, 1600, $procRoot));
    }

    public function testCgroupApplyFailureMessageIsSingleLine(): void
    {
        $message = \pmssUserConfigCgroupApplyFailureMessage("ali\nce", 7);

        $this->assertSame(
            'Warning: cgroup configuration failed for ali?ce (rc=7); update-step2 will check and retry slice policy drift',
            $message
        );
    }
}
