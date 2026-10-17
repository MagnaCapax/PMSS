<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';

final class TorrentClientsIdentityTest extends TestCase
{
    /** Fake proc content; no real tenant path is read by the predicate. */
    public function testLivenessAndNativeStopHaveDifferentExeRules(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-proc-');
        foreach ([101, 102, 103, 104] as $pid) {
            mkdir($root.'/'.$pid);
            file_put_contents($root.'/'.$pid.'/comm', "qbittorrent-nox\n");
            file_put_contents($root.'/'.$pid.'/status', "Name:\tqbittorrent-nox\nUid:\t1000 1000 1000 1000\n");
        }
        $exe = [
            101 => '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox',
            102 => '/home/t/bin/qbittorrent/custom/qbittorrent-nox',
            103 => '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox',
            104 => '/usr/bin/qbittorrent-nox',
        ];
        $ops = [
            'account' => static function (): array { return ['uid' => 1000]; },
            'pids' => static function (): array { return [101, 102, 103, 104]; },
            'read' => static function (string $path) use ($root) {
                return @file_get_contents(str_replace('/proc/', $root.'/', $path));
            },
            'namespace' => static function (int $pid): string { return $pid === 103 ? 'mnt:[2]' : 'mnt:[1]'; },
            'exe' => static function (int $pid) use ($exe): string { return $exe[$pid]; },
        ];
        $this->assertSame([101, 102, 104], \pmssTorrentClientLauncherLivenessPids('t', 'qbittorrent', $ops));
        $this->assertSame([101, 102], \pmssTorrentClientNativeStopPids('t', 'qbittorrent', $ops));
    }

    public function testUidAndNameMismatchAreExcluded(): void
    {
        $ops = [
            'account' => static function (): array { return ['uid' => 1000]; },
            'pids' => static function (): array { return [1, 2]; },
            'namespace' => static function (): string { return 'mnt:[1]'; },
            'read' => static function (string $path): string {
                if (substr($path, -5) === '/comm') return strpos($path, '/2/') !== false ? 'other' : 'rtorrent';
                return "Uid:\t2000 2000 2000 2000\n";
            },
        ];
        $this->assertSame([], \pmssTorrentClientLauncherLivenessPids('t', 'rtorrent', $ops));
        $this->assertSame([], \pmssTorrentClientNativeStopPids('t', 'rtorrent', $ops));
    }

    public function testUnknownClientAndUnsafeUserReject(): void
    {
        $this->assertSame([], \pmssTorrentClientLauncherLivenessPids('t', 'unknown'));
        $this->assertSame([], \pmssTorrentClientNativeStopPids('../t', 'rtorrent'));
    }
}
