<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/TorrentClientsCardsRenderTrait.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

final class TorrentClientsP3CatalogTest extends TestCase
{
    use TorrentClientsCardsRenderTrait;

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

    public function testOptionalWebPinLoadsOnlyForTransmission(): void
    {
        $entry = $this->entry();
        $entry['web'] = ['url' => ['https://example.invalid/web.tar.gz'], 'sha256' => str_repeat('b', 64)];
        $this->assertSame([$entry], $this->load($entry));
        foreach (['qbittorrent', 'rtorrent', 'deluge'] as $client) {
            $other = $entry;
            $other['client'] = $client;
            unset($other['rpcUnixSocket']);
            $this->assertSame([], $this->load($other));
        }
    }

    public function testMalformedWebMetadataRejectsTheCatalog(): void
    {
        $web = ['url' => ['https://example.invalid/web.tar.gz'], 'sha256' => str_repeat('b', 64)];
        foreach ([null, [], 'archive', ['url' => $web['url']], $web + ['extra' => true],
            array_replace($web, ['sha256' => str_repeat('B', 64)]),
            array_replace($web, ['sha256' => str_repeat('b', 63)]),
            array_replace($web, ['sha256' => []]),
            array_replace($web, ['url' => ['http://example.invalid/web']]),
            array_replace($web, ['url' => ['https://']]),
            array_replace($web, ['url' => []]),
            array_replace($web, ['url' => ['mirror' => 'https://example.invalid/web']]),
            array_replace($web, ['url' => [42]])] as $bad) {
            $entry = $this->entry();
            $entry['web'] = $bad;
            $this->assertSame([], $this->load($entry));
        }
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

    public function testConfigureSwitchWarningsMatchEachClient(): void
    {
        $versions = array_fill_keys(['qbittorrent', 'transmission', 'deluge', 'rtorrent'], ['4.1.3', '4.0.5']);
        $this->withProjection($this->entries($versions), function ($page): void {
            $cards = $page();
            foreach (['qbittorrent' => 'qBittorrent', 'transmission' => 'Transmission',
                'deluge' => 'Deluge', 'rtorrent' => 'rTorrent'] as $client => $label) {
                $expected = $client === 'transmission'
                    ? 'Restart Transmission with this version? Its settings are not part of the configuration backup.'
                    : 'Back up the current configuration and restart '.$label.' with this version?';
                $this->assertStringContainsString($expected, $cards[$client.'-version']);
            }
        });
    }

    public function testConfigureNamesLibtorrentBuildsThroughTheRenderer(): void
    {
        require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsVersion.php';
        $this->assertSame('5.2.4 (libtorrent 1.2)', \pmssConfigureTorrentClientVersionLabel('5.2.4-lt1.2'));
        $this->assertSame('4.3.9 (libtorrent 2.0)', \pmssConfigureTorrentClientVersionLabel('4.3.9-lt2.0'));
        $this->assertSame('5.3.0 (libtorrent 2.1)', \pmssConfigureTorrentClientVersionLabel('5.3.0-lt2.1'));
        foreach (['system', '0.16.25', '0.9.8-stickz7.2', 'not running', '5.2.4-lt3.0'] as $plain)
            $this->assertSame($plain, \pmssConfigureTorrentClientVersionLabel($plain));
        $this->withProjection($this->entries(['qbittorrent' => ['5.2.4-lt1.2', '4.3.9-lt2.0']]), function ($page): void {
            $this->assertStringContainsAllStrings(['<option value="5.2.4-lt1.2">5.2.4 (libtorrent 1.2)</option>',
                '<option value="4.3.9-lt2.0">4.3.9 (libtorrent 2.0)</option>'], $page()['qbittorrent-version']);
        });
        // TorrentClientsCardsRenderTest checks actual Configure index order for every card layout.
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
            'cff6d66bd7527f9eed7eaaaf931dccc061544efcada4e8b0ac3dbecb2422aab0',
            'd3dce8aaa8f5d013e0cd29a3246b46ba482217b16f74740d2a321c884a1ec45f',
        ], array_column($entries, 'sha256'));
        $this->assertSame([
            '5cd1d317b5ed798b92ac3d7f6fadb7187d32a76d62691e732769b8eb597ff790',
            'cf9d0c1e1e8ef494de78a5cb5b9a0fdfd28f404bb5b0cb1d2ab693c634155200',
        ], array_column(array_column($entries, 'web'), 'sha256'));
        foreach ($entries as $entry) {
            $this->assertSame('static', $entry['kind']);
            $this->assertSame(true, $entry['rpcUnixSocket']);
            $this->assertSame(false, $entry['disabled']);
            $this->assertSame([], $entry['cve']);
            $this->assertSame(['https://pulsedmedia.com/remote/pkg/transmission-daemon-'
                .$entry['version'].'-static-amd64-r2'], $entry['url']);
            $this->assertSame(['https://pulsedmedia.com/remote/pkg/transmission-web-'
                .$entry['version'].'-r1.tar.gz'], $entry['web']['url']);
        }
        $adr = (string) file_get_contents(dirname(__DIR__, 4).'/docs/adr/0108-transmission-socket-integration.md');
        $this->assertStringContainsString('4.1.3 as default and 4.0.5 as legacy', $adr);
        $this->assertStringContainsString("Do not\npublish 4.0.6 because of its tracker announce race.", $adr);
    }
}
