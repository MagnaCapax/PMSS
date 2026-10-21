<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

final class TorrentClientsCuratedDelugeTest extends TestCase
{
    private function entries(): array
    {
        return array_values(array_filter(\pmssTorrentClientsCatalogLoad(
            dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json'
        ), static function (array $entry): bool {
            return $entry['client'] === 'deluge' && $entry['kind'] === 'venv';
        }));
    }

    public function testEveryVersionPinsCompleteReviewedClosure(): void
    {
        $entries = $this->entries();
        $this->assertSame(['2.0.5', '2.1.1', '2.2.0'], array_column($entries, 'version'));
        $required = ['attrs', 'automat', 'cffi', 'constantly', 'cryptography', 'deluge',
            'hyperlink', 'idna', 'incremental', 'libtorrent', 'mako', 'markupsafe',
            'packaging', 'pyasn1', 'pyasn1_modules', 'pycparser', 'pyopenssl', 'pyxdg', 'rencode',
            'service_identity', 'setuptools', 'twisted', 'typing_extensions', 'zope_interface'];
        foreach ($entries as $entry) {
            $expected = $required;
            if ($entry['version'] === '2.0.5') $expected[] = 'six';
            sort($expected);
            $files = array_column($entry['wheels'], 'file');
            $names = array_map(static function (string $file): string { return strtolower(explode('-', $file)[0]); }, $files);
            sort($names);
            $this->assertSame($expected, $names);
            $this->assertTrue(in_array('deluge-'.$entry['version'].'-py3-none-any.whl', $files, true));
            $this->assertTrue(in_array(
                'libtorrent-2.1.1-cp311-cp311-manylinux_2_17_x86_64.manylinux2014_x86_64.whl', $files, true
            ));
            // Every Deluge 2.x imports pkg_resources, which setuptools >=81 removed, and
            // deluged's certificate code needs OpenSSL.crypto.X509Req, gone from newer pyOpenSSL.
            $this->assertTrue(in_array('setuptools-80.10.2-py3-none-any.whl', $files, true));
            $this->assertTrue(in_array('pyOpenSSL-24.2.1-py3-none-any.whl', $files, true));
            foreach ($entry['wheels'] as $wheel) {
                $this->assertSame(1, preg_match('/\A[a-f0-9]{64}\z/', $wheel['sha256']));
            }
            $this->assertSame('bookworm-cp311', $entry['compatWindow']);
            $this->assertFalse($entry['isDefault']);
            $this->assertFalse($entry['disabled']);
            $this->assertSame([], $entry['url']);
            $this->assertSame('', $entry['sha256']);
        }
        $this->assertFalse(file_exists(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.enabled'));
    }

    /** Exercise real command discovery using only isolated shell and os-release fixtures. */
    private function host(string $pythonVersion, string $codename, callable $check): void
    {
        $root = $this->pmssMakeTempDir('pmss-deluge-host-');
        file_put_contents($root.'/python3', "#!/bin/sh\nprintf '%s\\n' '".$pythonVersion."'\n");
        chmod($root.'/python3', 0755);
        $major = $codename === 'bookworm' ? '12' : '11';
        file_put_contents($root.'/os-release', "ID=debian\nVERSION_ID=$major\nVERSION_CODENAME=$codename\n");
        $this->pmssWithEnv(['PATH' => $root.':'.getenv('PATH'),
            'PMSS_OS_RELEASE_PATH' => $root.'/os-release'], $check);
    }

    public function testPythonWindowRejectsNonMatchingAndMalformedVersions(): void
    {
        foreach (['Python 3.9.2', 'Python 3.10.9', 'Python 3.12.0', 'Python 3.110.1', '', 'unknown'] as $version) {
            $this->host($version, 'bookworm', function (): void {
                foreach ($this->entries() as $entry) $this->assertFalse(\pmssTorrentClientsDelugeVenvCompatible($entry));
            });
        }
        $this->host('Python 3.11.2', 'bookworm', function (): void {
            foreach ($this->entries() as $entry) {
                $this->assertSame(php_uname('m') === 'x86_64', \pmssTorrentClientsDelugeVenvCompatible($entry));
            }
        });
    }

    public function testBullseyePython39SkipsBeforePipAndRetainsSystemFallback(): void
    {
        $this->host('Python 3.9.2', 'bullseye', function (): void {
            $root = $this->pmssMakeTempDir('pmss-deluge-skip-');
            $marker = $root.'/enabled';
            file_put_contents($marker, '');
            $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_TEST_MARKER' => $marker], function () use ($root): void {
                $calls = 0;
                foreach ($this->entries() as $entry) {
                    $this->assertFalse(\pmssTorrentClientsDelugeVenvCompatible($entry));
                    $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry,
                        $root.'/clients/deluge/'.$entry['version'], ['install' => static function () use (&$calls): void { $calls++; }]));
                }
                $this->assertSame(0, $calls);
                $results = \pmssTorrentClientsStoreConverge($this->entries(), ['root' => $root.'/clients',
                    'free' => static function (): int { return 10 * 1024 * 1024 * 1024; },
                    'size' => static function (): int { return 0; }, 'log' => static function (): void {}]);
                $this->assertSame(array_fill_keys(['deluge/2.0.5', 'deluge/2.1.1', 'deluge/2.2.0'], 'failed'), $results);
                // Uninstalled versions have no customer selection; launchers retain the system command.
                $this->assertSame(null, \pmssTorrentClientResolve($root.'/customer', 'deluge', $root.'/missing.json', $root.'/clients'));
            });
        });
    }

    public function testLegacyWindowRemainsCompatible(): void
    {
        $this->host('Python 3.9.2', 'bullseye', function (): void {
            $this->assertTrue(\pmssTorrentClientsDelugeVenvCompatible(['compatWindow' => 'bullseye-cp37-310']));
            $this->assertTrue(\pmssTorrentClientsDelugeVenvCompatible([]));
            $this->assertFalse(\pmssTorrentClientsDelugeVenvCompatible(['compatWindow' => 'unknown']));
        });
    }
}
