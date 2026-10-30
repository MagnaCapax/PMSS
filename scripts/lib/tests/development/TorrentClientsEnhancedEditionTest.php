<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 2).'/user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsVersion.php';

/** The Enhanced Edition entry carries its warning in the version and reads as its upstream release (ADR 0184). */
final class TorrentClientsEnhancedEditionTest extends TestCase
{
    private const VERSION = '5.2.4.10-ee-lt1.2-not-for-private-trackers';

    public function testCatalogOffersOneReHostedEnhancedEditionBuild(): void
    {
        $catalog = \pmssTorrentClientsCatalogLoad(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json');
        $entries = array_values(array_filter($catalog, static function ($entry) {
            return $entry['client'] === 'qbittorrent' && strpos($entry['version'], '-ee-') !== false;
        }));
        $this->assertSame(1, count($entries));
        $entry = $entries[0];
        $this->assertSame(self::VERSION, $entry['version']);
        $this->assertTrue(\pmssTorrentClientsVersionValid($entry['version']));
        $this->pmssAssertArraySubsetSame([
            'url' => ['https://pulsedmedia.com/remote/pkg/qbittorrent-enhanced-nox-5.2.4.10-static-amd64-r1'],
            'sha256' => '4b291578e28663c7d1c88bf41993a832c0eb68b3f9bd345716524c3f37d0565a', 'kind' => 'static',
            'compatFamily' => 'lt1.2',
            'note' => 'Community build of 5.2.4. Its authors ask that it not be used on private trackers.',
        ], $entry);
        $this->assertFalse($entry['disabled']);
        $this->assertFalse($entry['isDefault']);
    }

    public function testLabelKeepsThePrivateTrackerWarning(): void
    {
        $this->assertSame('5.2.4.10 Enhanced Edition (libtorrent 1.2), not for private trackers',
            \pmssConfigureTorrentClientVersionLabel(self::VERSION));
        $this->assertSame('5.2.4 (libtorrent 1.2)', \pmssConfigureTorrentClientVersionLabel('5.2.4-lt1.2'));
    }

    public function testEnhancedEditionReadsAsItsUpstreamReleaseOnBothSides(): void
    {
        foreach (['pmssQbittorrentBuildNumber', 'pmssQbittorrentFrontendBuildNumber'] as $number) {
            $this->assertSame('5.2.4', $number(self::VERSION));
            $this->assertSame('5.2.4', $number('5.2.4.10-ee-lt1.2'));
            $this->assertSame(null, $number('5.2.4.10-ee-lt2.2'));
            $this->assertSame(null, $number('5.2.4.10'));
        }
        // A 5.3 run left the WebUI keys in [WebUI]; the Enhanced Edition build still reads [Preferences].
        $config = "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=8081\n";
        $this->assertSame(['Preferences', 'WebUI\\'],
            \pmssQbittorrentWebUiSection($config, \pmssQbittorrentBuildNumber(self::VERSION)));
    }

    public function testMajorDowngradeGuardReadsTheEnhancedEditionLabel(): void
    {
        $this->assertTrue(\pmssConfigureTorrentClientMajorDowngrade(self::VERSION, '4.6.7-lt1.2'));
        $this->assertTrue(\pmssConfigureTorrentClientMajorDowngrade(self::VERSION, '4.3.9-lt2.0'));
        $this->assertFalse(\pmssConfigureTorrentClientMajorDowngrade(self::VERSION, '5.3.0-lt2.1'));
        $this->assertFalse(\pmssConfigureTorrentClientMajorDowngrade('5.3.0-lt2.1', self::VERSION));
        $this->assertFalse(\pmssConfigureTorrentClientMajorDowngrade('4.6.7-lt1.2', self::VERSION));
    }
}
