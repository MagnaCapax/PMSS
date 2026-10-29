<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/TorrentClientsP2Fixture.php';
require_once dirname(__DIR__, 2).'/torrentClients/postSetup.php';
require_once dirname(__DIR__, 2).'/user/recreateRestore.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

/** Dangling-link repair is independent of stopped configuration work. */
final class QbittorrentPostSetupTest extends TestCase
{
    use TorrentClientsP2Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pmssTrackEnvKeys(['PMSS_DRY_RUN', 'PMSS_HOME_DIR']);
    }

    public function testFailedFallbackRestoreStillRepairsDanglingSelection(): void
    {
        [$root, $home, $ops] = $this->fixture('restore-failed');
        $target = $root.'/store/qbittorrent/missing';
        $this->pmssCreateSymlinkOrSkip($target, $home.'/bin/qbittorrent/default');
        // Restore refusal cannot strand a dangling link; actual starts still enforce restore.
        $config = $home.'/.config/qBittorrent/qBittorrent.conf';
        $this->pmssCreateSymlinkOrSkip($config, dirname($config).'/qBittorrent_new.conf');
        $this->assertTrue(\pmssTorrentClientsPostSetupFallback('t', $home, 'qbittorrent', [], 'system', $ops));
        $this->assertFalse(is_link($home.'/bin/qbittorrent/default'));
        $notice = $home.'/.config/pmss/torrentClients/qbittorrent.notice';
        $this->assertStringContainsString("server's build is used", file_get_contents($notice));
        $this->assertSame("[Preferences]\nWebUI\\Port=23456\n", file_get_contents($config));
    }
    public function testMissingPanelHelpersCannotBlockDanglingLinkRepair(): void
    {
        [$root, $home, $ops] = $this->fixture('missing-helper');
        unlink($home.'/www/qbittorrentStart.php');
        $this->pmssCreateSymlinkOrSkip($root.'/store/qbittorrent/missing', $home.'/bin/qbittorrent/default');
        $this->assertTrue(\pmssTorrentClientsPostSetupFallback('t', $home, 'qbittorrent', [], 'system', $ops));
        $this->assertFalse(is_link($home.'/bin/qbittorrent/default'));
    }

    public function testRunningClientSkipsTheRepairRestoreAndStoppedClientRestores(): void
    {
        $calls = 0;
        foreach ([[101], []] as $pids) {
            $this->assertTrue(\pmssQbittorrentFrontendRestoreForRepair('/fixture/t', [
                'restore' => static function () use (&$calls): bool { $calls++; return true; },
            ] + self::pmssMakeCallbacks(['accountPids' => $pids])));
            $this->assertSame($pids === [] ? 1 : 0, $calls);
        }
    }

    public function testLinkRepairRequestsTheWaitBudgetBeforeRepairingALockedSelection(): void
    {
        [$root, $home, $ops] = $this->fixture('wait-for-start');
        $this->pmssCreateSymlinkOrSkip($root.'/store/qbittorrent/missing', $home.'/bin/qbittorrent/default');
        $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
        $lock = fopen($dir.'/qbittorrent.lock', 'ce');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $dispatched = false;
        try {
            $ops['run'] = function ($user, $home, $paths, $command) use ($lock, $dir, &$dispatched): bool {
                $other = fopen($dir.'/qbittorrent.lock', 'ce');
                $this->assertFalse(flock($other, LOCK_EX | LOCK_NB));
                fclose($other);
                // The shell has no clock seam: pin its blocking policy, then simulate the holder's exit at dispatch.
                $this->assertStringContainsString('flock -w 120 9 || exit 1;', $command);
                $this->assertStringNotContainsString('flock -n 9', $command);
                $dispatched = true;
                flock($lock, LOCK_UN);
                $rc = 1; $output = []; exec($command, $output, $rc); return $rc === 0;
            };
            $this->assertTrue(\pmssTorrentClientsPostSetupFallback('t', $home, 'qbittorrent', [], 'system', $ops));
            $this->assertTrue($dispatched);
            $this->assertFalse(is_link($home.'/bin/qbittorrent/default'));
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

}
