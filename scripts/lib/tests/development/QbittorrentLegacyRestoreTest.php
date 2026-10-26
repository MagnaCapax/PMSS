<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once __DIR__.'/../../user/qbittorrentRestore.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentRestore.php';
require_once __DIR__.'/../../user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

/** Regression fixtures for old names removed at migration 9 and the unstamped 4.3.9 build. */
final class QbittorrentLegacyRestoreTest extends TemporaryHomeRootTestCase
{
    public function testMigrationNineMappingsCoverEveryUpstreamEntryAndBothCopiesAgree(): void
    {
        $keys = \pmssQbittorrentLegacyKeys();
        $this->assertSame(74, count($keys));
        $this->assertSame($keys, \pmssQbittorrentFrontendLegacyKeys());
        $config = "[Meta]\nMigrationVersion=12\n";
        foreach ($keys as $new => $old) {
            list($section, $key) = explode('/', $new, 2);
            $config = \pmssQbittorrentConfigSet($config, $section, str_replace('/', '\\', $key), 'fixture-'.$new);
        }
        $root = \pmssQbittorrentRestoreContent($config);
        $this->assertSame($root, \pmssQbittorrentFrontendRestoreContent($config));
        $values = \pmssQbittorrentConfigValues($root);
        foreach ($keys as $new => $old) {
            list($section, $key) = explode('/', $old, 2);
            $this->assertSame('fixture-'.$new, $values[$section][str_replace('/', '\\', $key)]);
        }
        $this->assertSame($root, \pmssQbittorrentRestoreContent($root));
    }

    public function testMigrationNinePreservesExistingOldValuesAndHandlesAllPathShapes(): void
    {
        $config = "[Meta]\nMigrationVersion=12\n[AddNewTorrentDialog]\nEnabled=false\nTreeHeaderState=@ByteArray(state)\n"
            ."SavePathHistory=/home/qafix081\n[BitTorrent]\nSession\\DefaultSavePath=/new\nSession\\DHTEnabled=false\n"
            ."Session\\Port=50000\nTrackerEnabled=true\n[Network]\nProxy\\IP=proxy.example\n"
            ."[State]\nBannedIPs=192.0.2.1\n[Preferences]\nDownloads\\SavePath=/owner-edit\n";
        foreach (['pmssQbittorrentRestoreContent', 'pmssQbittorrentFrontendRestoreContent'] as $restore) {
            $values = \pmssQbittorrentConfigValues($restore($config));
            $this->assertSame('/owner-edit', $values['Preferences']['Downloads\\SavePath']);
            $this->assertSame('false', $values['Preferences']['Downloads\\NewAdditionDialog']);
            $this->assertSame('@ByteArray(state)', $values['AddNewTorrentDialog']['qt5\\treeHeaderState']);
            $this->assertSame('/home/qafix081', $values['TorrentAdditionDlg']['save_path_history']);
            $this->assertSame('false', $values['Preferences']['Bittorrent\\DHT']);
            $this->assertSame('50000', $values['Preferences']['Connection\\PortRangeMin']);
            $this->assertSame('true', $values['Preferences']['Advanced\\trackerEnabled']);
            $this->assertSame('proxy.example', $values['Preferences']['Connection\\Proxy\\IP']);
            $this->assertSame('192.0.2.1', $values['Preferences']['IPFilter\\BannedIPs']);
        }
    }

    public function testExportInverseFeedsTheMigrationNineOldestNames(): void
    {
        $config = file_get_contents(__DIR__.'/../fixtures/qbittorrentMigrated53.conf');
        $values = \pmssQbittorrentConfigValues(\pmssQbittorrentRestoreContent($config));
        $this->assertSame('/home/qafix081/export', $values['Preferences']['Downloads\\TorrentExportDir']);
        $this->assertSame('', $values['Preferences']['Downloads\\FinishedTorrentExportDir']);
    }

    public function testPre44RoundTripPreservesOwnerSpeedAndPasswordEditsWithoutChangingMeta(): void
    {
        $config = file_get_contents(__DIR__.'/../fixtures/qbittorrentMigrated53.conf');
        $config = \pmssQbittorrentConfigSet($config, 'BitTorrent', 'Session\GlobalUPSpeedLimit', '123');
        $config = \pmssQbittorrentConfigSet($config, 'Preferences', 'Connection\GlobalUPLimit', 'stale');
        foreach (['pmssQbittorrentRestoreContent', 'pmssQbittorrentFrontendRestoreContent'] as $restore) {
            $old = $restore($config, '4.3.9', '5.3.0');
            $this->assertSame('123', \pmssQbittorrentConfigValues($old)['Preferences']['Connection\GlobalUPLimit']);
            $edited = \pmssQbittorrentConfigSet($old, 'Preferences', 'Connection\GlobalUPLimit', '456', true);
            $edited = \pmssQbittorrentConfigSet($edited, 'Preferences', 'WebUI\Password_PBKDF2', '@ByteArray(owner:edit)', true);
            $this->assertSame($edited, $restore($edited, '4.3.9', '4.3.9'));
            $modern = \pmssQbittorrentConfigValues($restore($edited, '5.3.0', '4.3.9'));
            $this->assertSame('456', $modern['BitTorrent']['Session\GlobalUPSpeedLimit']);
            $this->assertSame('@ByteArray(owner:edit)', $modern['WebUI']['Password_PBKDF2']);
            $this->assertSame('@ByteArray(owner:edit)', $modern['Preferences']['WebUI\Password_PBKDF2']);
            $this->assertSame('12', $modern['Meta']['MigrationVersion']);
        }
    }

    public function testSystem42BuildOnMigratedConfigUsesPreferencesAndLeavesProxyPrivacyUntouched(): void
    {
        $config = "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\n[Network]\n"
            ."Proxy\\Profiles\\BitTorrent=true\nProxy\\Profiles\\RSS=false\nProxy\\Profiles\\Misc=false\n"
            ."Proxy\\HostnameLookupEnabled=true\n[Preferences]\nConnection\\ProxyOnlyForTorrents=false\n"
            ."[BitTorrent]\nSession\\ProxyHostnameLookup=false\nSession\\GlobalDLSpeedLimit=123\n";
        foreach (['pmssQbittorrentRestoreContent', 'pmssQbittorrentFrontendRestoreContent'] as $restore) {
            $old = $restore($config, '4.2.5', '5.3.0');
            $this->assertSame('23456', \pmssQbittorrentWebUiValue($old, 'Port', '4.2.5'));
            foreach ([$old, $restore($old, '5.3.0', '4.2.5')] as $result) {
                $values = \pmssQbittorrentConfigValues($result);
                $this->assertSame('12', $values['Meta']['MigrationVersion']);
                $this->assertSame('true', $values['Network']['Proxy\Profiles\BitTorrent']);
                $this->assertSame('false', $values['Network']['Proxy\Profiles\RSS']);
                $this->assertSame('false', $values['Network']['Proxy\Profiles\Misc']);
                $this->assertSame('true', $values['Network']['Proxy\HostnameLookupEnabled']);
            }
        }
    }
}
