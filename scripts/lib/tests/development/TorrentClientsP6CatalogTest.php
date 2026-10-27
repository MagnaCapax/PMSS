<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

final class TorrentClientsP6CatalogTest extends TestCase
{
    private function entry(): array
    {
        return ['client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv',
            'url' => [], 'sha256' => '', 'compatFamily' => '2', 'cve' => [],
            'disabled' => false, 'isDefault' => false,
            'wheels' => [['file' => 'deluge-2.1.1-py3-none-any.whl', 'sha256' => str_repeat('a', 64)]]];
    }

    private function load(array $entry): array
    {
        $path = $this->pmssMakeTempDir('pmss-p6-catalog-').'/catalog.json';
        file_put_contents($path, json_encode([$entry]));
        return \pmssTorrentClientsCatalogLoad($path);
    }

    public function testPinnedVenvAndCompatWindowLoad(): void
    {
        $entry = $this->entry();
        $this->assertSame([$entry], $this->load($entry));
        $entry['compatWindow'] = 'bullseye-cp37-310';
        $this->assertSame([$entry], $this->load($entry));
    }

    public function testNonVenvAndMalformedWheelsReject(): void
    {
        foreach (['static', 'system'] as $kind) {
            $entry = $this->entry(); $entry['kind'] = $kind;
            $this->assertSame([], $this->load($entry));
        }
        foreach (['../deluge.whl', 'deluge.zip'] as $file) {
            $entry = $this->entry(); $entry['wheels'][0]['file'] = $file;
            $this->assertSame([], $this->load($entry));
        }
        $entry = $this->entry(); $entry['wheels'][0]['sha256'] = 'bad';
        $this->assertSame([], $this->load($entry));
        $entry = $this->entry(); $entry['wheels'][] = $entry['wheels'][0];
        $this->assertSame([], $this->load($entry));
        $entry = $this->entry(); $entry['compatWindow'] = 'other';
        $this->assertSame([], $this->load($entry));
    }

    public function testResolverRequiresVenvProjectionForDelugeStore(): void
    {
        [$root, $home, $store] = $this->pmssTorrentClientStoreFixture('pmss-p6-resolve-',
            'deluge', '2.1.1', ['bin/deluged' => 'fixture'], 't@localhost');
        symlink($store.'/deluge/2.1.1', $home.'/bin/deluge/default');
        $projection = $root.'/projection.json';
        foreach (['static', 'venv'] as $kind) {
            file_put_contents($projection, json_encode(['versions' => [
                ['client' => 'deluge', 'version' => '2.1.1', 'kind' => $kind],
            ]]));
            $expected = $kind === 'venv' ? $store.'/deluge/2.1.1/bin/deluged' : null;
            $this->assertSame($expected, \pmssTorrentClientResolve($home, 'deluge', $projection, $store));
        }
    }

    public function testShippedCatalogKeepsDelugeSystemDefault(): void
    {
        $entries = \pmssTorrentClientsCatalogLoad(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json');
        foreach ($entries as $entry) {
            if ($entry['client'] === 'deluge' && $entry['isDefault']) {
                $this->assertSame('system', $entry['kind']);
                $this->assertSame('/usr/bin/deluged', $entry['binary']);
            }
        }
    }
}
