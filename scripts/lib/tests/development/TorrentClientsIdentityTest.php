<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';

final class TorrentClientsIdentityTest extends TestCase
{
    public function testDirectAccountBinBuildRetainsUidNamespaceAndExecutableChecks(): void
    {
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1000], 'pids' => [101, 102, 103, 104]]) + [
            'namespace' => static function ($pid): string { return $pid === 102 ? 'mnt:[foreign]' : 'mnt:[own]'; },
            'read' => static function ($path): string {
                return substr($path, -5) === '/comm' ? 'qbittorrent-nox'
                    : 'Uid: '.(strpos($path, '/103/') !== false ? '1001' : '1000');
            },
            'exe' => static function ($pid): string {
                return $pid === 104 ? '/home/other/bin/qbittorrent-nox' : '/home/qafix081/bin/qbittorrent-nox';
            },
        ];
        $this->assertSame([101], \pmssTorrentClientNativeStopPids('qafix081', 'qbittorrent', $ops));
    }

    /** Fake proc content; no real tenant path is read by the predicate. */
    public function testLivenessAndNativeStopHaveDifferentExeRules(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-proc-');
        foreach ([101, 102, 103, 104] as $pid) {
            $this->pmssWriteFiles($root.'/'.$pid, ['comm' => "qbittorrent-nox\n",
                'status' => "Name:\tqbittorrent-nox\nUid:\t1000 1000 1000 1000\n"]);
        }
        $exe = [
            101 => '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox',
            102 => '/home/qafix081/bin/qbittorrent/custom/qbittorrent-nox',
            103 => '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox',
            104 => '/usr/bin/qbittorrent-nox',
        ];
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1000], 'pids' => [101, 102, 103, 104]]) + [
            'read' => static function (string $path) use ($root) {
                return @file_get_contents(str_replace('/proc/', $root.'/', $path));
            },
            'namespace' => static function (int $pid): string { return $pid === 103 ? 'mnt:[2]' : 'mnt:[1]'; },
            'exe' => static function (int $pid) use ($exe): string { return $exe[$pid]; },
        ];
        $this->assertSame([101, 102, 104], \pmssTorrentClientLauncherLivenessPids('qafix081', 'qbittorrent', $ops));
        $this->assertSame([101, 102, 104], \pmssTorrentClientNativeStopPids('qafix081', 'qbittorrent', $ops));
    }

    /** Resolve system install links while leaving an account-owned bin link lexical. */
    public function testNativeStopResolvesOnlyInstallPrefixSymlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-links-');
        $installBin = $this->pmssEnsureDir($root.'/usr/local/bin');
        $versioned = $this->pmssWriteFile($installBin.'/qbittorrent-nox-4.6.7', 'binary');
        $this->pmssCreateSymlinkOrSkip('qbittorrent-nox-4.6.7', $installBin.'/qbittorrent-nox');
        $tenantTarget = $this->pmssEnsureDir($root.'/tenant-target');
        $this->pmssEnsureDir($root.'/home/qafix081/bin');
        $this->pmssCreateSymlinkOrSkip($tenantTarget, $root.'/home/qafix081/bin/qbittorrent');
        $tenantExe = $this->pmssWriteFile($tenantTarget.'/qbittorrent-nox', 'binary');
        foreach ([201, 202] as $pid) {
            $this->pmssWriteFiles($root.'/proc/'.$pid, ['comm' => "qbittorrent-nox\n",
                'status' => "Uid:\t1000 1000 1000 1000\n"]);
        }
        $exe = [201 => $versioned, 202 => $tenantExe];
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1000], 'pids' => [201, 202], 'namespace' => 'mnt:[1]']) + [
            'read' => static function (string $path) use ($root) {
                return @file_get_contents(str_replace('/proc/', $root.'/proc/', $path));
            },
            'exe' => static function (int $pid) use ($exe): string { return $exe[$pid]; },
            'installRoots' => [$installBin.'/qbittorrent-nox'],
            'accountRoots' => [$root.'/home/qafix081/bin/qbittorrent/'],
        ];
        $this->assertSame([201], \pmssTorrentClientNativeStopPids('qafix081', 'qbittorrent', $ops));
    }

    public function testUidAndNameMismatchAreExcluded(): void
    {
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1000], 'pids' => [1, 2], 'namespace' => 'mnt:[1]']) + [
            'read' => static function (string $path): string {
                if (substr($path, -5) === '/comm') return strpos($path, '/2/') !== false ? 'other' : 'rtorrent';
                return "Uid:\t2000 2000 2000 2000\n";
            },
        ];
        $this->assertSame([], \pmssTorrentClientLauncherLivenessPids('qafix081', 'rtorrent', $ops));
        $this->assertSame([], \pmssTorrentClientNativeStopPids('qafix081', 'rtorrent', $ops));
    }

    public function testUnknownClientAndUnsafeUserReject(): void
    {
        $this->assertSame([], \pmssTorrentClientLauncherLivenessPids('qafix081', 'unknown'));
        $this->assertSame([], \pmssTorrentClientNativeStopPids('../t', 'rtorrent'));
    }
}
