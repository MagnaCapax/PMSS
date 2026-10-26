<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentRestore.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentRestore.php';

/** Replay only value conversions required by renamed old keys, without proxy policy migrations. */
final class QbittorrentLegacyEnumTest extends TestCase
{
    public function testEveryUpstreamProxyAndRatioEnumSurvivesThePre44RoundTrip(): void
    {
        $proxy = ['None', 'HTTP', 'SOCKS5', 'HTTP_PW', 'SOCKS5_PW', 'SOCKS4'];
        $ratio = ['Stop', 'Remove', 'EnableSuperSeeding', 'RemoveWithContent'];
        foreach (['pmssQbittorrentRestoreContent', 'pmssQbittorrentFrontendRestoreContent'] as $restore)
            foreach ($proxy as $proxyOld => $proxyNew) foreach ($ratio as $ratioOld => $ratioNew) {
                $config = "[Meta]\nMigrationVersion=12\n[Network]\nProxy\\Type=".$proxyNew."\n"
                    ."Proxy\\Profiles\\BitTorrent=true\nProxy\\Profiles\\RSS=false\nProxy\\Profiles\\Misc=false\n"
                    ."Proxy\\HostnameLookupEnabled=true\n[BitTorrent]\nSession\\ShareLimitAction=".$ratioNew."\n";
                $old = $restore($config, '4.3.9', '5.3.0');
                $values = \pmssQbittorrentConfigValues($old);
                $this->assertSame((string) $proxyOld, $values['Preferences']['Connection\\ProxyType']);
                $this->assertSame((string) $ratioOld, $values['Preferences']['Bittorrent\\MaxRatioAction']);
                // The customer changes both choices while using 4.3.9, then returns to 5.x.
                $old = \pmssQbittorrentConfigSet($old, 'Preferences', 'Connection\\ProxyType', '2', true);
                $old = \pmssQbittorrentConfigSet($old, 'Preferences', 'Bittorrent\\MaxRatioAction', '3', true);
                $modern = $restore($old, '5.3.0', '4.3.9');
                $values = \pmssQbittorrentConfigValues($modern);
                $this->assertSame('SOCKS5', $values['Network']['Proxy\\Type']);
                $this->assertSame('RemoveWithContent', $values['BitTorrent']['Session\\ShareLimitAction']);
                $this->assertSame('true', $values['Network']['Proxy\\Profiles\\BitTorrent']);
                $this->assertSame('false', $values['Network']['Proxy\\Profiles\\RSS']);
                $this->assertSame('false', $values['Network']['Proxy\\Profiles\\Misc']);
                $this->assertSame('true', $values['Network']['Proxy\\HostnameLookupEnabled']);
                $this->assertSame('12', $values['Meta']['MigrationVersion']);
                $this->assertSame($modern, $restore($modern, '5.3.0', '5.3.0'));
            }
    }
}
