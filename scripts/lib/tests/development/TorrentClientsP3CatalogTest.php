<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

final class TorrentClientsP3CatalogTest extends TestCase
{
    private function entry(string $version = '4.1.3'): array
    {
        return ['client' => 'transmission', 'version' => $version,
            'url' => ['https://example.invalid/transmission'], 'sha256' => str_repeat('a', 64),
            'kind' => 'static', 'compatFamily' => '4.1', 'cve' => [],
            'disabled' => false, 'isDefault' => true, 'rpcUnixSocket' => true];
    }

    private function load(array $entry): array
    {
        $root = $this->pmssMakeTempDir('pmss-p3-catalog-');
        $path = $root.'/catalog.json';
        file_put_contents($path, json_encode([$entry]));
        return \pmssTorrentClientsCatalogLoad($path);
    }

    public function testStaticSocketEntryLoads(): void
    {
        $entry = $this->entry();
        $this->assertSame([$entry], $this->load($entry));
    }

    public function testMissingOrFalseCapabilityDoesNotLoad(): void
    {
        $entry = $this->entry();
        unset($entry['rpcUnixSocket']);
        $this->assertSame([], $this->load($entry));
        $entry['rpcUnixSocket'] = false;
        $this->assertSame([], $this->load($entry));
    }

    public function testSystemAndVenvEntriesDoNotLoad(): void
    {
        foreach (['system', 'venv'] as $kind) {
            $entry = $this->entry();
            $entry['kind'] = $kind;
            $this->assertSame([], $this->load($entry));
        }
    }

    public function testResolverRequiresPublishedSocketCapability(): void
    {
        $root = $this->pmssMakeTempDir('pmss-p3-resolve-');
        $home = $root.'/t';
        $store = $root.'/store';
        mkdir($home.'/bin/transmission', 0755, true);
        mkdir($store.'/transmission/4.1.3', 0755, true);
        file_put_contents($store.'/transmission/4.1.3/transmission-daemon', 'fixture');
        symlink($store.'/transmission/4.1.3', $home.'/bin/transmission/default');
        $projection = $root.'/projection.json';
        $version = ['client' => 'transmission', 'version' => '4.1.3', 'kind' => 'static'];
        file_put_contents($projection, json_encode(['versions' => [$version]]));
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'transmission', $projection, $store));
        $version['rpcUnixSocket'] = true;
        file_put_contents($projection, json_encode(['versions' => [$version]]));
        $this->assertSame($store.'/transmission/4.1.3/transmission-daemon',
            \pmssTorrentClientResolve($home, 'transmission', $projection, $store));
    }

    public function testShippedCatalogAndDocumentedVersionPolicy(): void
    {
        $path = dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json';
        foreach (\pmssTorrentClientsCatalogLoad($path) as $entry) {
            $this->assertFalse($entry['client'] === 'transmission');
        }
        $adr = (string) file_get_contents(dirname(__DIR__, 4).'/docs/adr/0108-transmission-socket-integration.md');
        $this->assertStringContainsString('4.1.3 as default and 4.0.5 as legacy', $adr);
        $this->assertStringContainsString('Do not', $adr);
        $this->assertStringContainsString('4.0.6', $adr);
    }
}
