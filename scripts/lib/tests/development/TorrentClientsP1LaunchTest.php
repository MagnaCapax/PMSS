<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';

/** Preserve the default launch command and exclude a switched PID. */
final class TorrentClientsP1LaunchTest extends TestCase
{
    public function testNoSelectionRetainsExactLegacyCommand(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/cron/checkQbittorrentInstances.php');
        $legacy = 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &';
        $this->assertSame(1, preg_match('/\|\| \((cd ~; nohup qbittorrent-nox -d >> \/dev\/null 2>&1 &)\)/', $source, $match));
        $this->assertSame($legacy, $match[1]);
        $this->assertStringContainsString('[ -L ~/bin/qbittorrent/default ] &&', $source);
        $this->assertStringContainsString('mkdir -m 700 -p ~/.config/pmss/torrentClients', $source);
        $this->assertStringContainsString('flock -n 9 || exit 0', $source);
        $this->assertStringContainsString('pmssTorrentClientLauncherLivenessPids', $source);
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
        $status = 0;
        exec('flock -n '.escapeshellarg($dir.'/qbittorrent.lock').' true', $unused, $status);
        $this->assertSame(1, $status);
        $this->assertTrue(\pmssTorrentClientResolverLaunch($home, 'qbittorrent'));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testReusedPidIsNotSignalled(): void
    {
        $reads = 0;
        $signals = array();
        $ops = array(
            'account' => static function (): array { return array('uid' => 1000); },
            'pids' => static function (): array { return array(123); },
            'namespace' => static function (): string { return 'mnt:[1]'; },
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
        );
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
            $dir = $this->pmssEnsureDir($proc.'/'.$pid.'/ns');
            $this->pmssWriteFile($proc.'/'.$pid.'/comm', "qbittorrent-nox\n");
            $this->pmssWriteFile($proc.'/'.$pid.'/status', "Uid:\t".posix_geteuid()." 0 0 0\n");
            $this->pmssCreateSymlinkOrSkip($pid === 103 ? 'mnt:[foreign]' : $ownNs, $dir.'/mnt');
            $binary = $pid === 102 ? $home.'/bin/qbittorrent/custom/qbittorrent-nox'
                : '/home/.pmss/clients/qbittorrent/5.1.0/qbittorrent-nox';
            $this->pmssCreateSymlinkOrSkip($binary, $proc.'/'.$pid.'/exe');
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
