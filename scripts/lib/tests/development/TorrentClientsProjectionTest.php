<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsVersion.php';

/** Publish isolated store fixtures; no installed host binaries or tenant data are used. */
final class TorrentClientsProjectionTest extends TestCase
{
    public function testProjectionCountsEachEnabledUnavailableEntryOnce(): void
    {
        $root = $this->pmssMakeTempDir('pmss-projection-counts-');
        $store = $root.'/store';
        $catalog = $expected = [];
        foreach (['qbittorrent', 'rtorrent', 'deluge', 'transmission'] as $client) {
            $kind = $client === 'deluge' ? 'venv' : 'static';
            $entry = ['client' => $client, 'version' => '1.0.0', 'kind' => $kind,
                'rpcUnixSocket' => true, 'isDefault' => true, 'compatFamily' => '1', 'cve' => []];
            $binary = \pmssTorrentClientsStoreBinaryPath($store, $client, '1.0.0', $kind);
            $this->pmssWriteFile($binary, 'binary');
            if ($client === 'deluge') $this->pmssWriteFile(dirname($binary).'/deluge-web', 'web');
            $catalog[] = $entry;
            $expected[] = ['client' => $client, 'version' => '1.0.0', 'kind' => $kind,
                'isDefault' => true, 'compatFamily' => '1', 'badges' => []]
                + ($client === 'transmission' ? ['rpcUnixSocket' => true] : []);
            $catalog[] = array_replace($entry, ['version' => 'missing']);
            $linked = \pmssTorrentClientsStoreBinaryPath($store, $client, 'linked', $kind);
            $this->pmssEnsureDir(dirname($linked));
            $this->pmssCreateSymlinkOrSkip($binary, $linked);
            $catalog[] = array_replace($entry, ['version' => 'linked']);
            // Both installed and missing disabled rows are excluded from counts and versions.
            $catalog[] = array_replace($entry, ['disabled' => true]);
            $catalog[] = array_replace($entry, ['version' => 'disabled-missing', 'disabled' => true]);
        }
        $this->pmssWriteFile($store.'/deluge/daemon-only/bin/deluged', 'daemon');
        $catalog[] = ['client' => 'deluge', 'version' => 'daemon-only', 'kind' => 'venv'];
        $this->pmssWriteFile($store.'/transmission/no-socket/transmission-daemon', 'binary');
        $catalog[] = ['client' => 'transmission', 'version' => 'no-socket', 'kind' => 'static', 'rpcUnixSocket' => false];
        $path = $root.'/public/clients.json';
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($catalog, $path): void {
            $this->assertTrue(\pmssTorrentClientsProjectionWrite($catalog, $path));
        });
        $data = json_decode(file_get_contents($path), true);
        $counts = ['qbittorrent' => 2, 'rtorrent' => 2, 'deluge' => 3, 'transmission' => 3];
        $this->assertSame($expected, $data['versions']);
        $this->assertSame($counts, $data['notInstalled']);
        $this->assertSame($counts, \pmssConfigureTorrentClientNotInstalledRead($path));
        $this->assertSame($expected, \pmssTorrentClientsProjectionVersionsRead($path));
    }

    public function testMissingSystemBinariesAreCountedAndInstalledSystemRowsAreUnchanged(): void
    {
        $root = $this->pmssMakeTempDir('pmss-system-counts-');
        $binary = $this->pmssWriteFile($root.'/binary', 'system');
        $entry = ['client' => 'qbittorrent', 'version' => 'system', 'kind' => 'system', 'binary' => $binary];
        $path = $root.'/public/clients.json';
        $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $path));
        $this->assertSame(0, \pmssConfigureTorrentClientNotInstalledRead($path)['qbittorrent']);
        $this->assertSame(['system'], array_keys(\pmssConfigureTorrentClientVersionsRead($path)));
        $entry['binary'] = $root.'/missing';
        $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $path));
        $this->assertSame(1, \pmssConfigureTorrentClientNotInstalledRead($path)['qbittorrent']);
        $this->assertSame([], \pmssConfigureTorrentClientVersionsRead($path));
    }

    public function testCountsDefaultToZeroForOldMalformedAndUnsafeProjections(): void
    {
        $root = $this->pmssMakeTempDir('pmss-counts-reader-');
        $path = $root.'/projection.json';
        $zero = ['qbittorrent' => 0, 'rtorrent' => 0, 'deluge' => 0, 'transmission' => 0];
        $this->assertSame($zero, \pmssConfigureTorrentClientNotInstalledRead($path));
        foreach (['{', 'null', '{"versions":null}', '{"versions":[]}',
            '{"versions":[],"notInstalled":false}', str_repeat(' ', 65537),
            '{"versions":[],"notInstalled":{"qbittorrent":-1,"rtorrent":"2","deluge":true,"transmission":1.5}}'] as $raw) {
            $this->pmssWriteFile($path, $raw);
            $this->assertSame($zero, \pmssConfigureTorrentClientNotInstalledRead($path));
        }
        $this->pmssWriteFile($path, '{"versions":[],"notInstalled":{"qbittorrent":2,"unknown":9}}');
        $this->assertSame(array_replace($zero, ['qbittorrent' => 2]), \pmssConfigureTorrentClientNotInstalledRead($path));
        $this->pmssCreateSymlinkOrSkip($path, $root.'/linked.json');
        $this->assertSame($zero, \pmssConfigureTorrentClientNotInstalledRead($root.'/linked.json'));
    }
}
