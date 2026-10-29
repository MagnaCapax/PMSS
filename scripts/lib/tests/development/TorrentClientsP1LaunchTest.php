<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';

/** Preserve the default launch command and exclude a switched PID. */
final class TorrentClientsP1LaunchTest extends TestCase
{
    public function testCronShellRoutesBothStartsUnderTheAccountLock(): void
    {
        // The cron entry point has no ops seam; keep only this shell-routing structural check.
        $source = $this->pmssReadRepoFile('scripts/cron/checkQbittorrentInstances.php');
        $this->assertStringContainsString('torrentClientsResolve.php qbittorrent --launch --held', $source);
        $this->assertStringContainsString('mkdir -m 700 -p ~/.config/pmss/torrentClients', $source);
        $this->assertStringContainsString('flock -n 9 || exit 0', $source);
        $this->assertOrderedStrings(['--launch --held --system 9>&-;',
            'else qbittorrent-nox -d 9>&-; fi;', 'pmssQbtStartRc=$?;'], $source);
        $this->assertStringContainsString('pmssQbittorrentWatchdogRunning($thisUser, $home)', $source);
        $this->assertStringContainsString("return pmssTorrentClientLauncherLivenessPids(\$user, 'qbittorrent') !== [];",
            $this->pmssReadRepoFile('scripts/lib/user/qbittorrentWatchdog.php'));
        $this->assertStringNotContainsString('nohup qbittorrent-nox', $source);
    }

    public function testSystemAndSelectedResolverLaunchesRestoreBeforeLaunchAndCheck(): void
    {
        foreach ([false, true] as $selected) {
            $home = $this->pmssMakeTempDir('pmss-qbt-resolver-behaviour-');
            if ($selected) {
                $this->pmssEnsureDir($home.'/bin/qbittorrent');
                $this->pmssCreateSymlinkOrSkip('custom', $home.'/bin/qbittorrent/default');
            }
            $events = [];
            $this->assertTrue(\pmssTorrentClientResolverLaunch($home, 'qbittorrent', false, [
                'system' => !$selected,
            ]
                + self::pmssMakeCallbacks(['accountPids' => [], 'resolve' => '/fixture/custom/qbittorrent-nox'])
                + self::pmssMakeCallbacks(['restore' => true, 'launch' => true, 'check' => true], $events)));
            $this->assertSame(['restore', 'launch', 'check'], $events);
        }
    }
    public function testSelectedLauncherExitsQuietlyOnBusySwitchLock(): void
    {
        $home = $this->pmssMakeTempDir('pmss-p1-launch-');
        $bin = $this->pmssEnsureDir($home.'/bin/qbittorrent/custom');
        $this->pmssWriteFile($bin.'/qbittorrent-nox', 'fixture');
        $this->pmssCreateSymlinkOrSkip('custom', $home.'/bin/qbittorrent/default');
        $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
        $handle = fopen($dir.'/qbittorrent.lock', 'c');
        flock($handle, LOCK_EX);
        $this->assertSame(1, $this->pmssExecShellCommand('flock -n '.escapeshellarg($dir.'/qbittorrent.lock').' true', [], '')['rc']);
        $this->assertTrue(\pmssTorrentClientResolverLaunch($home, 'qbittorrent'));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testLegacyResolverWithoutSelectionStillRefusesSystemLaunch(): void
    {
        $home = $this->pmssMakeTempDir('pmss-p1-unselected-');
        $this->assertFalse(\pmssTorrentClientResolverLaunch($home, 'qbittorrent'));
        $this->assertFalse(is_dir($home.'/.config'));
    }

    public function testReusedPidIsNotSignalled(): void
    {
        $reads = 0;
        $signals = array();
        $ops = array(
            'read' => static function (string $path): string {
                return substr($path, -5) === '/comm' ? "qbittorrent-nox\n" : "Uid:\t1000 1000 1000 1000\n";
            },
            'exe' => static function () use (&$reads): string {
                $reads++;
                return $reads === 1 ? '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox' : '/tmp/reused';
            },
            'signal' => static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = array($pid, $signal); return true;
            },
        ) + self::pmssMakeCallbacks(['account' => array('uid' => 1000), 'pids' => array(123), 'namespace' => 'mnt:[1]']);
        $this->assertSame(0, \pmssTorrentClientNativeStop('t', 'qbittorrent', 15, $ops));
        $this->assertSame(array(), $signals);
    }

    public function testCustomerNativeIdentityExcludesForeignMountNamespace(): void
    {
        $root = $this->pmssMakeTempDir('pmss-p1-proc-');
        $home = $root.'/t';
        $proc = $root.'/proc';
        $ownNs = readlink('/proc/self/ns/mnt');
        foreach (array(101, 102, 103) as $pid) {
            $this->pmssWriteFiles($proc.'/'.$pid, ['comm' => "qbittorrent-nox\n", 'status' => "Uid:\t".posix_geteuid()." 0 0 0\n"]);
            $binary = $pid === 102 ? $home.'/bin/qbittorrent/custom/qbittorrent-nox'
                : '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox';
            $this->pmssWriteSymlinks($proc.'/'.$pid, ['ns/mnt' => $pid === 103 ? 'mnt:[foreign]' : $ownNs, 'exe' => $binary]);
        }
        $this->assertSame(array(101, 102), \pmssConfigureTorrentClientNativePids($home, $proc));
    }

    public function testWebUiStopUsesSharedNativeIdentityForSelectedBuilds(): void
    {
        $root = dirname(__DIR__, 2).'/user/';
        $this->assertStringContainsString("pmssTorrentClientNativeStopPids(\$user, 'qbittorrent')",
            (string) file_get_contents($root.'qbittorrentWebUiAuth.php'));
        $this->assertStringContainsString("pmssTorrentClientNativeStopPids(\$user, 'qbittorrent')",
            (string) file_get_contents($root.'qbittorrentWebUiAuthState.php'));
    }
}
