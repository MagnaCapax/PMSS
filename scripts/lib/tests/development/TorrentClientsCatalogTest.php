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

    public function testShippedSystemManifestLoads(): void
    {
        $path = dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json';
        $this->assertSame(3, count(\pmssTorrentClientsCatalogLoad($path)));
    }
}
