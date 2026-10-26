<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

/** Account-owned path and lock fixtures never touch real process/config state. */
final class QbittorrentStoppedConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pmssTrackEnvKeys(['PMSS_HOME_DIR']);
        putenv('PMSS_HOME_DIR='.(getenv('PMSS_TEST_TEMP_ROOT') ?: sys_get_temp_dir()));
    }

    public function testAnySameUidProcessBlocksRestoreRegardlessOfNamespaceOrInstallPath(): void
    {
        $proc = $this->pmssMakeTempDir('pmss-qbt-processes-');
        foreach ([101 => ['qbittorrent-nox', 1234], 102 => ['qbittorrent-nox', 2345],
            103 => ['another-app', 1234], 104 => ['qbittorrent-nox', 1234]] as $pid => list($comm, $uid)) {
            $this->pmssWriteFile($proc.'/'.$pid.'/comm', $comm."\n");
            $this->pmssWriteFile($proc.'/'.$pid.'/status', "Uid:\t".$uid."\t".$uid."\n");
            $this->pmssEnsureDir($proc.'/'.$pid.'/ns');
            $this->pmssCreateSymlinkOrSkip('mnt:['.$pid.']', $proc.'/'.$pid.'/ns/mnt');
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
        $this->assertFalse(\pmssQbittorrentPasswordApplyStopped('t', $home, 'secret', [
            'prepare' => static function () use (&$calls): bool { $calls[] = 'prepare'; return true; },
            'password' => static function () use (&$calls): bool { $calls[] = 'password'; return true; },
            'notice' => static function () use (&$calls): void { $calls[] = 'notice'; },
        ]));
        $this->assertSame([], $calls);
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.notice'));
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
                'prepare' => static function (): bool { return true; },
                'password' => static function () use (&$writes): bool { $writes++; return true; },
            ]));
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
            $this->assertStringContainsString('password synchronization failed', $notice);
            $this->assertStringContainsString('lock timeout', $notice);
            $this->assertStringNotContainsString('never-log-this', $notice);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

}
