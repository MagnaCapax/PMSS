<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';

final class TorrentClientsCatalogTest extends TestCase
{
    private function entry(): array
    {
        return [
            'client' => 'qbittorrent', 'version' => '5.1.0',
            'url' => ['https://example.invalid/qbittorrent'], 'sha256' => str_repeat('a', 64),
            'kind' => 'static', 'compatFamily' => '5', 'cve' => [],
            'disabled' => false, 'isDefault' => true,
        ];
    }

    private function load(array $entries): array
    {
        $dir = $this->pmssMakeTempDir('pmss-clients-catalog-');
        $path = $dir.'/catalog.json';
        file_put_contents($path, json_encode($entries));
        return \pmssTorrentClientsCatalogLoad($path);
    }

    public function testValidManifestLoads(): void
    {
        $this->assertSame([$this->entry()], $this->load([$this->entry()]));
    }

    public function testBadChecksumRejectsWholeManifest(): void
    {
        $entry = $this->entry();
        $entry['sha256'] = 'bad';
        $this->assertSame([], $this->load([$entry]));
    }

    public function testBadVersionCharsetRejectsWholeManifest(): void
    {
        $entry = $this->entry();
        $entry['version'] = '../escape';
        $this->assertSame([], $this->load([$entry]));
    }

    public function testDuplicateClientVersionRejectsWholeManifest(): void
    {
        $this->assertSame([], $this->load([$this->entry(), $this->entry()]));
    }

    public function testBadClientAndUrlReject(): void
    {
        $entry = $this->entry();
        $entry['client'] = 'other';
        $this->assertSame([], $this->load([$entry]));
        $entry = $this->entry();
        $entry['url'] = ['http://example.invalid/app'];
        $this->assertSame([], $this->load([$entry]));
    }

    public function testShippedManifestDefaultsToCuratedRtorrentAndTransmission(): void
    {
        $path = dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json';
        $defaults = array_values(array_filter(\pmssTorrentClientsCatalogLoad($path), static function ($entry) {
            return $entry['isDefault'];
        }));
        // qBittorrent and Deluge retain system defaults; rTorrent and Transmission use store builds.
        $this->assertSame(['qbittorrent', 'rtorrent', 'deluge', 'transmission'], array_column($defaults, 'client'));
        $this->assertSame(['system', 'static', 'system', 'static'], array_column($defaults, 'kind'));
        $this->assertSame(['system', '0.16.25', 'system', '4.1.3'], array_column($defaults, 'version'));
    }
}
