<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once __DIR__.'/../../user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

/** Account-owned path and lock fixtures never touch real process/config state. */
final class QbittorrentStoppedConfigTest extends TemporaryHomeRootTestCase
{
    public function testAnySameUidProcessBlocksRestoreRegardlessOfNamespaceOrInstallPath(): void
    {
        $proc = $this->pmssMakeTempDir('pmss-qbt-processes-');
        foreach ([101 => ['qbittorrent-nox', 1234], 102 => ['qbittorrent-nox', 2345],
            103 => ['another-app', 1234], 104 => ['qbittorrent-nox', 1234]] as $pid => list($comm, $uid)) {
            $this->pmssWriteFiles($proc.'/'.$pid, ['comm' => $comm."\n", 'status' => "Uid:\t".$uid."\t".$uid."\n"]);
            $this->pmssWriteSymlinks($proc.'/'.$pid, ['ns/mnt' => 'mnt:['.$pid.']']);
        }
        foreach (['pmssQbittorrentAccountPids', 'pmssQbittorrentFrontendAccountPids'] as $pids) {
            $this->assertSame([101, 104], $pids($proc, 1234));
            $this->assertSame([102], $pids($proc, 2345));
            $this->assertSame([], $pids($proc, 3456));
        }
        $home = $this->pmssMakeTempDir('pmss-qbt-stopped-');
        $config = "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
        foreach (['pmssQbittorrentRestore', 'pmssQbittorrentFrontendRestore'] as $restore) {
            $this->assertFalse($restore($home, ['proc' => $proc, 'uid' => 1234]));
            $this->assertSame($config, file_get_contents($path));
        }
    }

    public function testContainerInAnotherNamespaceBlocksOnlyWhenItMountsTheConfigDir(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-ns-home-');
        $config = $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $dev = stat($config)['dev']; $id = (($dev >> 8) & 0xfff | (($dev >> 32) & ~0xfff)).':'.(($dev & 0xff) | (($dev >> 12) & ~0xff));
        // pid => mountinfo of a same-UID process in another namespace (null: no table); own-namespace 100 always counts.
        $cases = [201 => ["30 20 0:99 / / rw - overlay overlay rw"], 202 => ["31 20 $id /.config/qBittorrent /config rw - ext4 /dev/x rw"],
            203 => ["32 20 $id / /host rw - ext4 /dev/x rw"], 204 => ["33 20 $id /other /data rw - ext4 /dev/x rw"], 205 => null,
            206 => ["34 20 $id /.config/qBittorrent/qBittorrent.conf /c.conf rw - ext4 /dev/x rw"]];
        foreach (['pmssQbittorrentAccountPids', 'pmssQbittorrentFrontendAccountPids'] as $pids) {
            $proc = $this->pmssMakeTempDir('pmss-qbt-ns-proc-');
            $this->pmssWriteSymlinks($proc.'/self', ['ns/mnt' => 'mnt:[1]']);
            foreach ([100 => 1] + array_fill_keys(array_keys($cases), 2) as $pid => $ns) {
                $this->pmssWriteFiles($proc.'/'.$pid, ['comm' => "qbittorrent-nox\n", 'status' => "Uid:\t1234\t1234\n"]
                    + (isset($cases[$pid]) && $cases[$pid] !== null ? ['mountinfo' => implode("\n", $cases[$pid])."\n"] : []));
                $this->pmssWriteSymlinks($proc.'/'.$pid, ['ns/mnt' => 'mnt:['.$ns.']']);
            }
            $this->assertSame([100, 202, 203, 205, 206], $pids($proc, 1234, $config));
        }
    }

    public function testAccountPathGuardAllowsLinkedParentsAndHardlinkedRegularLeaves(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-paths-');
        $dir = $this->pmssEnsureDir($home.'/private/qBittorrent');
        $this->pmssCreateSymlinkOrSkip($home.'/private', $home.'/.config');
        $path = $this->pmssWriteFile($dir.'/qBittorrent.conf', 'original');
        link($path, $dir.'/other.conf');
        $this->pmssCreateSymlinkOrSkip($path, $dir.'/leaf-link');
        foreach (['pmssQbittorrentFileSafe', 'pmssQbittorrentFrontendFileSafe'] as $safe) {
            $this->assertTrue($safe($home.'/.config/qBittorrent/qBittorrent.conf'));
            $this->assertTrue($safe($dir.'/absent.conf'));
            $this->assertFalse($safe($dir.'/leaf-link'));
            $this->assertFalse($safe($dir));
            $this->assertFalse($safe(''));
            $this->assertFalse($safe($path."\0"));
        }
        putenv('PMSS_HOME_DIR='.$home.'/elsewhere');
        $this->assertFalse(\pmssQbittorrentFileSafe($path));
        $this->assertFalse(\pmssQbittorrentFrontendFileSafe($path));
    }

    public function testPasswordSyncBeforeQbittorrentHasAProfileDoesNothing(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-noprofile-');
        $calls = [];
        $this->assertFalse(\pmssQbittorrentPasswordApplyStopped('t', $home, 'secret',
            self::pmssMakeCallbacks(['prepare' => true, 'password' => true, 'notice' => null], $calls)));
        $this->assertSame([], $calls);
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.notice'));
    }

    public function testSuccessfulPasswordSyncClearsOnlyAnOlderSyncFailureNotice(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-pwnotice-');
        mkdir($home.'/.config/qBittorrent', 0700, true);
        $notice = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.notice',
            "qBittorrent password synchronization failed: another qbittorrent-nox process of this account is running (not started by PMSS).\n");
        $ops = self::pmssMakeCallbacks(['prepare' => true, 'password' => true]);
        $this->assertTrue(\pmssQbittorrentPasswordApplyStopped('t', $home, 'secret', $ops));
        $this->assertSame('', file_get_contents($notice));
        file_put_contents($notice, "Downgrading qBittorrent can corrupt existing data; switch refused.\n");
        $this->assertTrue(\pmssQbittorrentPasswordApplyStopped('t', $home, 'secret', $ops));
        $this->assertStringContainsString('switch refused', file_get_contents($notice));
    }

    public function testPasswordSyncRetriesBusyLockThenRunsTheWriteOnce(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-lock-');
        mkdir($home.'/.config/qBittorrent', 0700, true);
        $path = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.lock', '');
        $lock = fopen($path, 'c');
        flock($lock, LOCK_EX);
        $pauses = 0;
        $writes = 0;
        try {
            $this->assertTrue(\pmssQbittorrentPasswordApplyStopped('t', $home, 'secret', [
                'pause' => static function () use ($lock, &$pauses): void {
                    if (++$pauses === 3) flock($lock, LOCK_UN);
                },
                'password' => static function () use (&$writes): bool { $writes++; return true; },
            ] + self::pmssMakeCallbacks(['prepare' => true])));
            $this->assertSame(3, $pauses);
            $this->assertSame(1, $writes);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function testPasswordLockTimeoutIsVisibleAndNeverWritesThePassword(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-lock-timeout-');
        mkdir($home.'/.config/qBittorrent', 0700, true);
        $path = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.lock', '');
        $lock = fopen($path, 'c');
        flock($lock, LOCK_EX);
        $pauses = 0;
        try {
            $this->assertFalse(\pmssQbittorrentPasswordApplyStopped('t', $home, 'never-log-this', [
                'pause' => static function () use (&$pauses): void { $pauses++; },
                'notice' => static function ($home, $message): void {
                    file_put_contents($home.'/.config/pmss/torrentClients/qbittorrent.notice', $message);
                },
                'prepare' => static function (): bool { throw new \RuntimeException('Busy lock allowed a write'); },
            ]));
            $this->assertSame(300, $pauses);
            $notice = file_get_contents(dirname($path).'/qbittorrent.notice');
            $this->assertStringContainsAndOmitsStrings(['password synchronization failed', 'lock timeout'], ['never-log-this'], $notice);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

}
