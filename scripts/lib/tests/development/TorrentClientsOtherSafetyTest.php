<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** Reject unsafe path shapes and preserve socket-only RPC preparation. */
final class TorrentClientsOtherSafetyTest extends TestCase
{
    public function testTransmissionSwitchAndStartRefuseDisabledAccountsBeforeWork(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transmission-disabled-');
        $this->pmssEnsureDir($home.'/bin/transmission');
        $this->pmssWriteFile($home.'/.transmissionDisable', '');
        $unexpected = static function (): void { throw new \RuntimeException('Disabled switch did work'); };
        $result = \pmssConfigureTorrentClientOtherSwitch($home,
            ['client' => 'transmission', 'version' => '4.1.3'],
            ['versions' => ['4.1.3' => ['kind' => 'static', 'rpcUnixSocket' => true]],
                'backup' => $unexpected, 'stop' => $unexpected, 'start' => $unexpected]);
        $this->assertSame(409, $result['status']);
        $this->assertSame('Start Transmission in Apps before switching versions.', $result['message']);
        $this->assertFalse(is_dir($home.'/.config'));
        $this->assertFalse(\pmssConfigureTorrentClientOtherStart($home, 'transmission', '4.1.3'));
        $this->assertFalse(is_dir($home.'/.config'));
    }

    public function testProjectionFiltersEachClientAndRejectsUnsafeData(): void
    {
        $root = $this->pmssMakeTempDir('pmss-other-projection-');
        $path = $root.'/projection.json';
        $this->pmssWriteFile($path, json_encode(array('versions' => array(
            array('client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv'),
            array('client' => 'transmission', 'version' => '4.1.3', 'kind' => 'static', 'rpcUnixSocket' => true),
            array('client' => 'qbittorrent', 'version' => '5.1.0', 'kind' => 'static'),
            array('client' => 'deluge', 'version' => '../bad', 'kind' => 'venv'),
            array('client' => 'transmission', 'version' => 'bad', 'kind' => 'other'),
        ))));
        $this->assertSame(array('2.1.1'), array_keys(\pmssConfigureTorrentClientVersionsRead($path, 'deluge')));
        $this->assertSame(array('4.1.3'), array_keys(\pmssConfigureTorrentClientVersionsRead($path, 'transmission')));
        $this->assertSame(array('5.1.0'), array_keys(\pmssConfigureTorrentClientVersionsRead($path)));
        $link = $root.'/linked.json';
        $this->pmssCreateSymlinkOrSkip($path, $link);
        $this->assertSame(array(), \pmssConfigureTorrentClientVersionsRead($link, 'deluge'));
    }

    public function testRepointRequiresRealDirectoryAndClientBinaries(): void
    {
        foreach (array('deluge', 'transmission', 'rtorrent') as $client) {
            $home = $this->pmssMakeTempDir('pmss-other-repoint-');
            $this->pmssEnsureDir($home.'/bin/'.$client);
            $target = $this->pmssEnsureDir($home.'/build');
            $binary = $client === 'deluge' ? '/bin/deluged'
                : ($client === 'rtorrent' ? '/rtorrent' : '/transmission-daemon');
            if ($client === 'deluge') $this->pmssEnsureDir($target.'/bin');
            $this->assertFalse(\pmssConfigureTorrentClientOtherRepoint($home, $client, 'v1', $target));
            $this->pmssWriteFile($target.$binary, 'fixture');
            if ($client === 'deluge') {
                $this->assertFalse(\pmssConfigureTorrentClientOtherRepoint($home, $client, 'v1', $target));
                $this->pmssWriteFile($target.'/bin/deluge-web', 'fixture');
            }
            $this->assertTrue(\pmssConfigureTorrentClientOtherRepoint($home, $client, 'v1', $target));
            $this->assertSame($target, readlink($home.'/bin/'.$client.'/default'));
            unlink($home.'/bin/'.$client.'/default');
            $this->pmssWriteFile($home.'/bin/'.$client.'/default', 'owned file');
            $this->assertFalse(\pmssConfigureTorrentClientOtherRepoint($home, $client, 'v1', $target));
        }
    }

    public function testTransmissionSettingsArePrivateAndSocketOnly(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transmission-settings-').'/t';
        $this->pmssEnsureDir($home.'/.config/transmission-daemon');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $path = $home.'/.config/transmission-daemon/settings.json';
        $this->pmssWriteFile($path, '{"rpc-password":"old-secret","download-dir":"/data"}');
        $this->assertTrue(\pmssTransmissionSettingsPrepare($home));
        $content = file_get_contents($path);
        $this->assertFalse(strpos($content, 'old-secret') !== false);
        $this->assertSame('/data', json_decode($content, true)['download-dir']);
        $this->assertTrue(\pmssTransmissionSettingsReady($home));
        $this->assertSame(0600, fileperms($path) & 0777);
        chmod($path, 0644);
        clearstatcache(true, $path);
        $this->assertTrue(\pmssTransmissionSettingsPrepare($home));
        $this->assertSame(0600, fileperms($path) & 0777);
        unlink($path);
        $this->pmssCreateSymlinkOrSkip($home.'/.lighttpd/missing', $path);
        $this->assertFalse(\pmssTransmissionSettingsPrepare($home));
    }

    public function testWatchdogLaunchesRespectClientSwitchLocks(): void
    {
        $root = dirname(__DIR__, 3).'/cron/';
        $deluge = file_get_contents($root.'checkDelugeInstances.php');
        $transmission = file_get_contents($root.'transmissionInstancesCheck.php');
        $this->assertSame(2, substr_count($deluge, 'flock -n 9 || exit 0'));
        $this->assertStringContainsString('torrentClients/deluge.lock', $deluge);
        $this->assertStringContainsString('torrentClients/transmission.lock', $transmission);
        $this->assertStringContainsString('flock -n 9 || exit 0', $transmission);
    }
}
