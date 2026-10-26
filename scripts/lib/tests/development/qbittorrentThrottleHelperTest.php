<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrent.php';

/** Build-aware caps are transformations, without probing any installed daemon. */
final class QbittorrentThrottleHelperTest extends TestCase
{
    public function testBuildVersionOverridesConfigMigrationForTheThrottleKey(): void
    {
        foreach (['4.3.9' => ['Preferences', 'Connection\\GlobalUPLimit'],
            '4.4.0' => ['BitTorrent', 'Session\\GlobalUPSpeedLimit'],
            '5.2.4-lt1.2' => ['BitTorrent', 'Session\\GlobalUPSpeedLimit'],
            '5.3.0' => ['BitTorrent', 'Session\\GlobalUPSpeedLimit']] as $build => $expected) {
            foreach (['', "[Meta]\nMigrationVersion=12\n"] as $config)
                $this->assertSame($expected, \pmssQbittorrentThrottleKey($config, $build));
        }
    }

    public function testUnknownBuildFallsBackToMigrationTwo(): void
    {
        foreach (['' => false, 'bad' => false, '1' => false, '2' => true, '12' => true, '"02"' => true] as $migration => $modern) {
            $key = \pmssQbittorrentThrottleKey("[Meta]\nMigrationVersion=".$migration."\n", null);
            $this->assertSame($modern ? 'BitTorrent' : 'Preferences', $key[0]);
        }
    }

    public function testModernBuildIgnoresAbsentOrStaleLegacyAlias(): void
    {
        foreach (['', "[Preferences]\nConnection\\GlobalUPLimit=999\n"] as $alias) {
            $config = "[BitTorrent]\nSession\\GlobalUPSpeedLimit=512\n".$alias;
            $this->assertFalse(\pmssQbittorrentThrottleDiffers($config, 512, '5.2.4'));
            $this->assertTrue(\pmssQbittorrentThrottleDiffers($config, 256, '5.2.4'));
        }
        $this->assertTrue(\pmssQbittorrentThrottleDiffers("[Preferences]\nConnection\\GlobalUPLimit=512\n", 512, '5.2.4'));
    }

    public function testUnlimitedDaemonSaveDoesNotScheduleAnotherRestart(): void
    {
        foreach (['', '0', '000', '"0"'] as $value) {
            $config = $value === '' ? '' : "[BitTorrent]\nSession\\GlobalUPSpeedLimit=".$value."\n";
            foreach ([null, 0] as $throttle)
                $this->assertFalse(\pmssQbittorrentThrottleDiffers($config, $throttle, '5.2.4'));
        }
        $this->assertFalse(\pmssQbittorrentThrottleDiffers("[BitTorrent]\nSession\\GlobalUPSpeedLimit=bad\n", 0, '5.2.4'));
    }

    public function testRemovalDeletesBothKeysAndDuplicatesOnlyWithinTheirSections(): void
    {
        $config = "[Preferences]\r\nConnection\\GlobalUPLimit=512\r\nConnection\\GlobalUPLimit=512\r\nLocale=en\r\n"
            ."[BitTorrent]\r\nSession\\GlobalUPSpeedLimit=512\r\nSession\\GlobalUPSpeedLimit=512\r\n"
            ."[Other]\r\nConnection\\GlobalUPLimit=7\r\n";
        $expected = "[Preferences]\r\nLocale=en\r\n[BitTorrent]\r\n[Other]\r\nConnection\\GlobalUPLimit=7\r\n";
        $this->assertSame($expected, \pmssQbittorrentThrottleContent($config, 0, '5.2.4', 512));
        $this->assertSame($expected, \pmssQbittorrentThrottleContent($config, null, '4.3.9', 512));
    }

    public function testOldBuildWritesOldKeyEvenWhenMigrationIsTwelve(): void
    {
        $config = "[Meta]\nMigrationVersion=12\n[Preferences]\nLocale=en\n";
        $updated = \pmssQbittorrentThrottleContent($config, 123, '4.3.9');
        $this->assertSame($config."Connection\\GlobalUPLimit=123\n", $updated);
        $this->assertFalse(\pmssQbittorrentThrottleDiffers($updated, 123, '4.3.9'));
    }

    public function testRemovalDoesNotDeleteAnAliasTheCustomerChanged(): void
    {
        $config = "[Preferences]\nConnection\\GlobalUPLimit=300\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=500\n";
        $updated = \pmssQbittorrentThrottleContent($config, null, '5.3.0', 500);
        $this->assertStringContainsString('Connection\\GlobalUPLimit=300', $updated);
        $this->assertStringNotContainsString('Session\\GlobalUPSpeedLimit', $updated);
        $this->assertSame($config, \pmssQbittorrentThrottleContent($config, null, '5.3.0'));
    }

    public function testProvisioningTemplateRetainsItsSeedAndGetsModernDefaultKey(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');
        $config = str_replace('##uploadThrottleLine', 'Connection\\GlobalUPLimit=123', $template);
        $updated = \pmssQbittorrentThrottleContent($config, 123, '5.2.4');
        $this->assertSame('123', \pmssQbittorrentConfigValues($updated)['BitTorrent']['Session\\GlobalUPSpeedLimit']);
        $this->assertStringContainsString('##uploadThrottleLine', $template);
        $this->assertStringNotContainsString('MigrationVersion=', $updated);
    }

    public function testRepeatedPreStartRestoreCannotReplaceNewThrottleWithStaleAlias(): void
    {
        require_once __DIR__.'/../../user/qbittorrentRestore.php';
        $config = "[Meta]\nMigrationVersion=12\n[Preferences]\nConnection\\GlobalUPLimit=1\n"
            ."[BitTorrent]\nSession\\GlobalUPSpeedLimit=2\n";
        foreach (['4.3.9' => '5.2.4', '5.2.4' => '4.3.9'] as $last => $build) {
            $updated = \pmssQbittorrentThrottleContent($config, 512, $build);
            $restored = \pmssQbittorrentRestoreContent($updated, $build, $last);
            $this->assertFalse(\pmssQbittorrentThrottleDiffers($restored, 512, $build));
        }
    }
}
