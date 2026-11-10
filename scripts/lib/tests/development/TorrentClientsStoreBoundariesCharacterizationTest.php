<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';
require_once dirname(__DIR__, 2).'/torrentClients/projection.php';

/** Lock store/projection policy differences through their existing public entrypoints. */
final class TorrentClientsStoreBoundariesCharacterizationTest extends TestCase
{
    public function testStaticPinsGateConvergenceButProjectionOnlyRequiresPresence(): void
    {
        $root = $this->pmssMakeTempDir('pmss-store-boundary-');
        $store = $root.'/clients';
        $binary = $this->pmssWriteFile($store.'/rtorrent/fixture/rtorrent', 'fixture');
        $entry = ['client' => 'rtorrent', 'version' => 'fixture', 'kind' => 'static',
            'sha256' => hash('sha256', 'fixture'), 'isDefault' => true];
        foreach ([true, false] as $matches) {
            $entry['sha256'] = $matches ? hash('sha256', 'fixture') : str_repeat('a', 64);
            $events = [];
            $ops = ['root' => $store] + self::pmssMakeCallbacks([
                'free' => 10 * 1024 * 1024 * 1024, 'size' => 7, 'install' => false,
            ], $events);
            $ops['log'] = $this->pmssMakeArrayLogger($events);
            $this->assertSame(['rtorrent/fixture' => $matches ? 'installed' : 'failed'],
                \pmssTorrentClientsStoreConverge([$entry], $ops));
            $this->assertSame($matches ? [] : ['free', 'size', 'install',
                '[WARN] torrent client rtorrent/fixture install failed'], $events);
            $this->assertSame('fixture', readlink($store.'/rtorrent/default'));
            $path = $root.'/public/clients.json';
            $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($entry, $path): void {
                $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $path));
            });
            $this->assertSame(['fixture'], array_column($this->pmssReadJsonArrayFile($path)['versions'], 'version'));
            $this->assertSame('fixture', file_get_contents($binary));
        }
    }

    public function testDelugeCompletenessHasTheSameMeaningForStoreAndProjection(): void
    {
        $root = $this->pmssMakeTempDir('pmss-store-deluge-boundary-');
        $store = $root.'/clients';
        foreach (['missing', 'daemon', 'complete', 'daemon-link', 'web-link', 'web-dir'] as $case) {
            $dir = $store.'/deluge/'.$case.'/bin';
            $this->pmssEnsureDir($dir);
            if ($case !== 'missing') $this->pmssWriteFile($dir.'/deluged', 'daemon');
            if (in_array($case, ['complete', 'daemon-link', 'web-link'], true)) $this->pmssWriteFile($dir.'/deluge-web', 'web');
            if ($case === 'daemon-link') {
                rename($dir.'/deluged', $dir.'/target');
                $this->pmssCreateSymlinkOrSkip('target', $dir.'/deluged');
            }
            if ($case === 'web-link') {
                rename($dir.'/deluge-web', $dir.'/target');
                $this->pmssCreateSymlinkOrSkip('target', $dir.'/deluge-web');
            }
            if ($case === 'web-dir') $this->pmssEnsureDir($dir.'/deluge-web');
            $entry = ['client' => 'deluge', 'version' => $case, 'kind' => 'venv'];
            $events = [];
            $ops = ['root' => $store] + self::pmssMakeCallbacks([
                'free' => 0, 'size' => 0, 'install' => false,
            ], $events);
            $ops['log'] = $this->pmssMakeArrayLogger($events);
            $this->assertSame(['deluge/'.$case => $case === 'complete' ? 'installed' : 'skipped-space'],
                \pmssTorrentClientsStoreConverge([$entry], $ops));
            $this->assertSame($case === 'complete' ? [] : ['free', 'size',
                '[SKIP] torrent client deluge/'.$case.' insufficient /home free space or unreadable set size'], $events);
            $path = $root.'/public/clients.json';
            $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($entry, $path): void {
                $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $path));
            });
            $data = $this->pmssReadJsonArrayFile($path);
            $this->assertSame($case === 'complete' ? [$case] : [], array_column($data['versions'], 'version'));
            $this->assertSame($case === 'complete' ? 0 : 1, $data['notInstalled']['deluge']);
        }
    }

    public function testWheelRejectionsMatchAtCatalogAndDirectInstallBoundaries(): void
    {
        $root = $this->pmssMakeTempDir('pmss-store-wheel-boundary-');
        $store = $root.'/clients';
        $this->pmssEnsureDir($store.'/deluge');
        $good = ['file' => 'fixture-1-py3-none-any.whl', 'sha256' => hash('sha256', 'wheel')];
        $this->pmssWriteFile($store.'/wheels/'.$good['file'], 'wheel');
        foreach ([null, false, [], 'wheel', ['file' => 1, 'sha256' => $good['sha256']],
            array_replace($good, ['file' => '../fixture.whl']), array_replace($good, ['file' => 'fixture.zip']),
            array_replace($good, ['sha256' => 1]), array_replace($good, ['sha256' => str_repeat('a', 63)]),
            array_replace($good, ['sha256' => str_repeat('g', 64)]), $good,
            array_replace($good, ['sha256' => strtoupper($good['sha256'])]),
        ] as $index => $wheel) {
            $entry = ['client' => 'deluge', 'version' => 'fixture', 'kind' => 'venv',
                'url' => [], 'sha256' => '', 'disabled' => false, 'isDefault' => false,
                'compatFamily' => '2', 'cve' => [], 'wheels' => [$wheel]];
            $path = $this->pmssWriteFile($root.'/catalog.json', json_encode([$entry]));
            $valid = $index >= 10;
            $this->assertSame($valid ? [$entry] : [], \pmssTorrentClientsCatalogLoad($path));
            $events = [];
            $ops = self::pmssMakeCallbacks(['fetch' => null, 'install' => null], $events);
            $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $store.'/deluge/fixture', $ops));
            // A valid wheel reaches the installer, whose no-op deliberately leaves the CLI absent.
            $this->assertSame($valid ? ['install'] : [], $events);
        }
    }
}
