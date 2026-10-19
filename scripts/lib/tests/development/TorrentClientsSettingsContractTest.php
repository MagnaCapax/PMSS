<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/transmissionSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/transmissionLaunch.php';

/** Compare the root writer with the customer writer and launcher validator. */
final class TorrentClientsSettingsContractTest extends TestCase
{
    public function testBothDeployTreesAgreeOnSocketSettings(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transmission-contract-').'/t';
        $this->pmssEnsureDir($home.'/.config/transmission-daemon');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $path = $home.'/.config/transmission-daemon/settings.json';
        $socket = $home.'/.lighttpd/transmission.sock';
        $rpc = '/user-t/transmission/';

        // The key set and values come from the two loaded production sources.
        $root = json_decode(\pmssTransmissionSettingsContent('{}', $socket, $rpc), true);
        $customer = \pmssTransmissionManagedSettings($home);
        $this->assertSame($root, $customer);
        $this->assertSame($root, json_decode(\pmssTransmissionSettingsContent('{}', $socket, $rpc, false), true));
        $this->assertTrue(\pmssTransmissionSettingsPrepare($home));
        $this->assertSame($root, json_decode((string) file_get_contents($path), true));
        $this->assertTrue(\pmssTransmissionSettingsReady($home));

        // Every managed key must be enforced by the launch readiness check.
        foreach ($root as $key => $value) {
            $changed = $root;
            $changed[$key] = $value === false ? true : false;
            file_put_contents($path, json_encode($changed));
            $this->assertFalse(\pmssTransmissionSettingsReady($home));
        }
    }

    public function testBothDeployTreesAgreeOnSnakeCasePolicy(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transmission-snake-').'/t';
        $this->pmssEnsureDir($home.'/.config/transmission-daemon');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $path = $home.'/.config/transmission-daemon/settings.json';
        $raw = '{"rpc_bind_address":"old","download-dir":"/keep"}';
        file_put_contents($path, $raw);
        $root = json_decode(\pmssTransmissionSettingsContent($raw,
            $home.'/.lighttpd/transmission.sock', '/user-t/transmission/'), true);
        $this->assertTrue(\pmssTransmissionSettingsPrepare($home));
        $this->assertSame($root, json_decode((string) file_get_contents($path), true));
        $this->assertTrue(\pmssTransmissionSettingsReady($home));
    }
}
