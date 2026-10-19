<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsIncAppProcess.php';

/** Exercise the shared customer toggle decision without starting host daemons. */
final class TorrentClientsToggleLaunchTest extends TestCase
{
    private function fixture(string $client, bool $selected, bool $enabled): array
    {
        $home = $this->pmssMakeTempDir('pmss-toggle-launch-');
        $marker = $home.'/torrentClients.enabled';
        if ($selected) {
            $this->pmssEnsureDir($home.'/bin/'.$client.'/store');
            $this->pmssCreateSymlinkOrSkip('store', $home.'/bin/'.$client.'/default');
        }
        if ($enabled) $this->pmssWriteFile($marker, 'enabled');
        return array($home, $marker);
    }

    private function starts(string $client, string $home, string $marker): array
    {
        $calls = array();
        $legacy = $client === 'qbittorrent'
            ? 'zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &'
            : \pmssCustomerDelugeSystemCommand();
        \pmssCustomerTorrentClientToggleStart($client,
            static function () use (&$calls, $legacy): void { $calls[] = array('system', $legacy); },
            $home, $marker,
            static function (string $command) use (&$calls): void { $calls[] = array('selected', $command); });
        return $calls;
    }

    public function testSelectedBuildUsesResolverForBothClients(): void
    {
        foreach (array('qbittorrent', 'deluge') as $client) {
            list($home, $marker) = $this->fixture($client, true, true);
            $component = $client === 'qbittorrent' ? ' --held' : ' both';
            $this->assertSame(array(array('selected', 'cd ~; php ~/www/torrentClientsResolve.php '
                .$client.' --launch'.$component.' >/dev/null 2>&1')), $this->starts($client, $home, $marker));
        }
    }

    public function testMissingSelectionRunsByteIdenticalSystemCommands(): void
    {
        foreach (array('qbittorrent', 'deluge') as $client) {
            list($home, $marker) = $this->fixture($client, false, true);
            $expected = $client === 'qbittorrent'
                ? 'zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &'
                : 'nohup deluged -l /home/$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &';
            $this->assertSame(array(array('system', $expected)), $this->starts($client, $home, $marker));
        }
    }

    public function testMissingMarkerRunsByteIdenticalSystemCommands(): void
    {
        foreach (array('qbittorrent', 'deluge') as $client) {
            list($home, $marker) = $this->fixture($client, true, false);
            $expected = $client === 'qbittorrent'
                ? 'zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &'
                : 'nohup deluged -l /home/$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &';
            $this->assertSame(array(array('system', $expected)), $this->starts($client, $home, $marker));
        }
    }

    public function testBusySwitchLockSkipsBothLaunchPaths(): void
    {
        foreach (array('qbittorrent', 'deluge') as $client) {
            foreach (array(true, false) as $selected) {
                list($home, $marker) = $this->fixture($client, $selected, true);
                $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
                $lock = fopen($dir.'/'.$client.'.lock', 'c');
                flock($lock, LOCK_EX);
                $this->assertSame(array(), $this->starts($client, $home, $marker));
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function testSwitchLockIsHeldDuringSelectedAndSystemLaunch(): void
    {
        foreach (array('qbittorrent', 'deluge') as $client) {
            foreach (array(true, false) as $selected) {
                list($home, $marker) = $this->fixture($client, $selected, true);
                $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
                $check = function () use ($dir, $client): void {
                    $other = fopen($dir.'/'.$client.'.lock', 'c');
                    $this->assertFalse(flock($other, LOCK_EX | LOCK_NB));
                    fclose($other);
                };
                \pmssCustomerTorrentClientToggleStart($client, $check, $home, $marker, $check);
            }
        }
    }

    public function testEndpointsKeepLegacyShellCallsAndDisableHandlers(): void
    {
        $qb = $this->pmssReadRepoFile('etc/skel/www/qbittorrent.php');
        $deluge = $this->pmssReadRepoFile('etc/skel/www/deluge.php');
        $this->assertStringContainsString("pmssCustomerTorrentClientToggleStart('qbittorrent'", $qb);
        $this->assertStringContainsString("passthru('zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');", $qb);
        $this->assertStringContainsString("pmssCustomerTorrentClientToggleStart('deluge'", $deluge);
        $this->assertStringContainsString('pmssFrontendShellExec(pmssCustomerDelugeSystemCommand());', $deluge);
        $this->assertStringContainsString("pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['qBittorrent']['binaries']);", $qb);
        $this->assertStringContainsString("pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['Deluge']['binaries']);", $deluge);
    }
}
