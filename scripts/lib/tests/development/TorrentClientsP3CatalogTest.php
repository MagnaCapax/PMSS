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
        $entries = array_values(array_filter(\pmssTorrentClientsCatalogLoad($path), static function ($entry) {
            return $entry['client'] === 'transmission';
        }));
        // Pin the curated pair, including the default policy and socket-only capability.
        $this->assertSame(['4.1.3', '4.0.5'], array_column($entries, 'version'));
        $this->assertSame([true, false], array_column($entries, 'isDefault'));
        $this->assertSame(['4.1', '4.0'], array_column($entries, 'compatFamily'));
        $this->assertSame([
            '3fb81fb39f08ceec528fa724c7c3438dc1e43273d3a48a338237eab712382193',
            '5d3c87a01503f69a9c958dceb99ea44418bff2445558fd0df1c3c919aa935019',
        ], array_column($entries, 'sha256'));
        foreach ($entries as $entry) {
            $this->assertSame('static', $entry['kind']);
            $this->assertSame(true, $entry['rpcUnixSocket']);
            $this->assertSame(false, $entry['disabled']);
            $this->assertSame([], $entry['cve']);
            $this->assertSame(['https://pulsedmedia.com/remote/pkg/transmission-daemon-'
                .$entry['version'].'-static-amd64'], $entry['url']);
        }
        $adr = (string) file_get_contents(dirname(__DIR__, 4).'/docs/adr/0108-transmission-socket-integration.md');
        $this->assertStringContainsString('4.1.3 as default and 4.0.5 as legacy', $adr);
        $this->assertStringContainsString("Do not\npublish 4.0.6 because of its tracker announce race.", $adr);
    }
}
