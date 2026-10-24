<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';
require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
require_once dirname(__DIR__, 2).'/update/apps/torrentClientStore.php';
require_once dirname(__DIR__, 2).'/user/qbittorrentVersion.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsVersion.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

/** Exercise the shipped catalog through installation, publication and customer reads. */
final class TorrentClientsCuratedQbittorrentTest extends TestCase
{
    private function entries(): array
    {
        $catalog = \pmssTorrentClientsCatalogLoad(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json');
        return array_values(array_filter($catalog, static function ($entry) {
            return $entry['client'] === 'qbittorrent' && $entry['kind'] === 'static';
        }));
    }

    public function testAllSevenSeriesHaveBothPinnedStaticFamilies(): void
    {
        $entries = $this->entries();
        $this->assertSame(14, count($entries));
        $expected = [];
        foreach (['4.3.9', '4.4.5', '4.5.5', '4.6.7', '5.0.5', '5.1.4', '5.2.4'] as $version) {
            foreach (['1.2', '2.0'] as $family) $expected[] = $version.'-lt'.$family;
        }
        $this->assertSame($expected, array_column($entries, 'version'));
        foreach ($entries as $entry) {
            [$version, $family] = explode('-lt', $entry['version']);
            $this->assertSame(1, preg_match('/\A[a-f0-9]{64}\z/', $entry['sha256']));
            $this->assertSame(1, count($entry['url']));
            $prefix = 'https://github.com/userdocs/qbittorrent-nox-static/releases/download/release-';
            $pattern = '~\A'.preg_quote($prefix.$version.'_v'.$family.'.', '~').'[0-9]+/x86_64-qbittorrent-nox\z~';
            $this->assertSame(1, preg_match($pattern, $entry['url'][0]));
            $this->assertFalse($entry['disabled']);
            $this->assertFalse($entry['isDefault']);
            $this->assertSame($family === '1.2' ? 'lt1.2' : $version, $entry['compatFamily']);
            $this->assertSame($family === '1.2' ? null : (version_compare($version, '5.0.1', '>=')
                ? 'SimplePreadPwrite' : 'Posix'), \pmssQbittorrentDiskIoType($entry['compatFamily']));
        }
    }

    public function testInstalledCuratedVersionsReachPickerAndResolver(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbit-curated-');
        $store = $root.'/pmss/clients';
        mkdir(dirname($store), 0755);
        $entries = $this->entries();
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($entries, $root, $store): void {
            $this->assertTrue(\pmssTorrentClientsProjectionWrite($entries, $root.'/public/catalog.json'));
            $this->assertSame([], \pmssConfigureTorrentClientVersionsRead($root.'/public/catalog.json'));
            // The injected installer writes fixture bytes only; no network or daemon is used.
            $result = \pmssTorrentClientsStoreConverge($entries, [
                'root' => $store, 'free' => static function (): int { return 10 * 1024 ** 3; },
                'size' => static function (): int { return 0; },
                'install' => static function (array $entry, string $binary): bool {
                    return basename($binary) === 'qbittorrent-nox' && file_put_contents($binary, 'fixture') === 7;
                }, 'log' => static function (): void {},
            ]);
            $this->assertSame(array_fill(0, 14, 'installed'), array_values($result));
            $this->assertFalse(file_exists($store.'/qbittorrent/default'));
            $this->assertTrue(\pmssTorrentClientsProjectionWrite($entries, $root.'/public/catalog.json'));
            $picker = \pmssConfigureTorrentClientVersionsRead($root.'/public/catalog.json');
            $this->assertSame(array_column($entries, 'version'), array_keys($picker));
            mkdir($root.'/t/bin/qbittorrent', 0755, true);
            foreach ($entries as $entry) {
                $link = $root.'/t/bin/qbittorrent/default';
                symlink($store.'/qbittorrent/'.$entry['version'], $link);
                $binary = $store.'/qbittorrent/'.$entry['version'].'/qbittorrent-nox';
                $this->assertSame($binary, \pmssTorrentClientResolve($root.'/t', 'qbittorrent', $root.'/public/catalog.json', $store));
                $this->assertSame(0755, fileperms($binary) & 0777);
                unlink($link);
            }
            // A catalog entry alone must never advertise an unavailable binary.
            unlink($store.'/qbittorrent/'.$entries[0]['version'].'/qbittorrent-nox');
            $this->assertTrue(\pmssTorrentClientsProjectionWrite($entries, $root.'/public/catalog.json'));
            $this->assertSame(13, count(\pmssConfigureTorrentClientVersionsRead($root.'/public/catalog.json')));
        });
    }

    public function testExactSpaceReserveSkipsCuratedInstallAndPreservesOldBinary(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbit-reserve-');
        mkdir($root.'/qbittorrent/old', 0755, true);
        file_put_contents($root.'/qbittorrent/old/qbittorrent-nox', 'old');
        foreach ([null, 3 * 1024 ** 3, 3 * 1024 ** 3 + 10] as $free) {
            $result = \pmssTorrentClientsStoreConverge($this->entries(), [
                'root' => $root, 'free' => static function () use ($free) { return $free; },
                'size' => static function (): int { return 10; },
                'install' => static function (): bool { throw new \RuntimeException('must not install'); },
                'log' => static function (): void {},
            ]);
            $this->assertSame(array_fill(0, 14, 'skipped-space'), array_values($result));
            $this->assertSame('old', file_get_contents($root.'/qbittorrent/old/qbittorrent-nox'));
        }
    }

    public function testOptionalIncludeContainsExceptionsAndErrorsAtEveryStage(): void
    {
        foreach (['load', 'store', 'project'] as $stage) {
            foreach ([\RuntimeException::class, \Error::class] as $type) {
                $events = [];
                $ops = ['load' => function (): array { return $this->entries(); },
                    'store' => static function (): void {}, 'project' => static function (): bool { return true; }];
                $ops[$stage] = static function () use ($stage, $type, &$events): void {
                    $events[] = $stage;
                    throw new $type('curated fixture failure');
                };
                \pmssTorrentClientStoreRun($ops);
                $this->assertSame([$stage], $events);
            }
        }
    }
}
