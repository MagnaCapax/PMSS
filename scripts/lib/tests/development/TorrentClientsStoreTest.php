<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';
require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
// The runner's tracked-file inventory must also exercise this uncommitted addition.
require_once __DIR__.'/TorrentClientsTransmissionWebTest.php';

final class TorrentClientsStoreTest extends TestCase
{
    private function entry(): array
    {
        return [
            'client' => 'rtorrent', 'version' => '0.9.9', 'kind' => 'static',
            'url' => ['https://example.invalid/rtorrent'], 'sha256' => str_repeat('a', 64),
            'isDefault' => true, 'disabled' => false, 'compatFamily' => '0.9', 'cve' => [],
        ];
    }

    public function testInsufficientSpaceSkipsWithoutDownloadOrAbort(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-space-').'/store';
        $events = [];
        $results = \pmssTorrentClientsStoreConverge([$this->entry()], [
            'root' => $root,
            'free' => static function (): int { return 3 * 1024 * 1024 * 1024 + 9; },
            'size' => static function (): int { return 10; },
            'install' => static function () use (&$events): bool { $events[] = 'download'; return true; },
            'log' => static function (string $line) use (&$events): void { $events[] = $line; },
        ]);
        $this->assertSame(['rtorrent/0.9.9' => 'skipped-space'], $results);
        $this->assertSame(1, count($events));
        $this->assertStringContainsString('insufficient /home free space', $events[0]);
        $this->assertFalse(file_exists($root));
    }

    public function testStoreSizeRequiresRepresentableUnsignedCommandOutput(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-size-');
        foreach ([
            ['0', 0, 0], ['00042', 0, 42], [(string) PHP_INT_MAX, 0, PHP_INT_MAX],
            [PHP_INT_MAX.'0', 0, null], ['000'.PHP_INT_MAX.'0', 0, null],
            ['-1', 0, null], ['1.5', 0, null], ['invalid', 0, null], ['', 0, null],
            ['42', 1, null],
        ] as [$digits, $rc, $expected]) {
            $bin = $this->pmssMakeExecutableStub('du', "#!/bin/sh\nprintf '%s\\n' "
                .escapeshellarg($digits."\t".$root)."\nexit ".$rc."\n", 'pmss-clients-du-');
            $this->pmssWithPathPrefix($bin, function () use ($root, $expected): void {
                $this->assertSame($expected, \pmssTorrentClientsStoreSetSize($root));
            });
        }
    }

    public function testStoreSizeRejectsMalformedPathsWithoutRunningDu(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-size-path-');
        symlink($root, $root.'/linked');
        $log = $root.'/calls';
        $bin = $this->pmssMakeInvocationLogStub('du', $log, 'pmss-clients-du-path-');
        $this->pmssWithPathPrefix($bin, function () use ($root): void {
            foreach (['', "\0".$root, $root."\0", $root.'/linked'] as $path) {
                $this->assertSame(null, \pmssTorrentClientsStoreSetSize($path));
            }
            $this->assertSame(0, \pmssTorrentClientsStoreSetSize($root.'/missing'));
        });
        $this->assertFalse(file_exists($log));
    }

    public function testFreeSpaceMeasurementRejectsInvalidNumbersBeforeCasting(): void
    {
        // Only the namespaced filesystem probe is replaced; conversion uses the real library.
        $script = 'namespace StoreSpaceFixture; function disk_free_space($path) { return $GLOBALS["bytes"]; }';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/torrentClients/store.php', 'StoreSpaceFixture');
        $script .= '$results = []; foreach ([false, null, "42", [], -1, -0.5, INF, NAN, '
            .'(float) PHP_INT_MAX, PHP_INT_MAX * 2.0, 0, 0.0, 42.75, PHP_INT_MAX] as $bytes) {'
            .'$GLOBALS["bytes"] = $bytes; $results[] = pmssTorrentClientsStoreFreeSpace(); } echo json_encode($results);';
        $this->assertSame(array_merge(array_fill(0, 10, null), [0, 0, 42, PHP_INT_MAX]),
            $this->pmssRunInlinePhpJson($script));
    }

    public function testSpaceReserveUsesExactIntegerBoundariesBeforeInstallation(): void
    {
        $base = $this->pmssMakeTempDir('pmss-clients-reserve-');
        $reserve = 3 * 1024 * 1024 * 1024;
        foreach ([
            [-1, 0, false], [PHP_INT_MAX, -1, false], [PHP_INT_MAX, PHP_INT_MAX, false],
            [null, 0, false], [PHP_INT_MAX, null, false], ['9999999999', 0, false],
            [$reserve, 0, false], [$reserve + 10, 10, false], [$reserve + 11, 10, true],
            [PHP_INT_MAX, PHP_INT_MAX - $reserve, false],
            [PHP_INT_MAX, PHP_INT_MAX - $reserve - 1, true],
        ] as $index => [$free, $size, $attempt]) {
            $root = $base.'/case-'.$index.'/store';
            $calls = 0;
            $result = \pmssTorrentClientsStoreConverge([$this->entry()], [
                'root' => $root,
                'free' => static function () use ($free) { return $free; },
                'size' => static function () use ($size) { return $size; },
                // Stop at the install boundary; no download or daemon is needed.
                'install' => static function () use (&$calls): bool { $calls++; return false; },
                'log' => static function (): void {},
            ]);
            $this->assertSame(['rtorrent/0.9.9' => $attempt ? 'failed' : 'skipped-space'], $result);
            $this->assertSame($attempt ? 1 : 0, $calls);
            $this->assertSame($attempt, is_dir($root));
        }
    }

    public function testProjectionIsAtomicPublicAndHostOnly(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-projection-');
        $binary = $root.'/binary';
        file_put_contents($binary, 'shipped');
        $entry = $this->entry();
        $entry['kind'] = 'system';
        $entry['binary'] = $binary;
        $entry['version'] = 'system';
        $entry['cve'] = ['CVE-2025-1234'];
        $path = $root.'/public/torrentClients.json';
        $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $path));
        $before = fileinode($path);
        $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $path));
        clearstatcache(true, $path);
        $this->assertFalse($before === fileinode($path));
        $this->assertSame(0644, fileperms($path) & 0777);
        $raw = file_get_contents($path);
        $this->assertStringContainsString('CVE-2025-1234', $raw);
        $this->assertStringNotContainsString('t@localhost', $raw);
        $this->assertStringNotContainsString($root, $raw);
        $this->assertSame(1, count(glob($root.'/public/*')));
    }

    public function testVenvArchiveAcceptsRegularMembersButRejectsLinks(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-archive-');
        file_put_contents($root.'/rtorrent', 'binary');
        $safe = $root.'/safe.tar.gz';
        $this->pmssCreateTarGzArchive($safe, $root, ['rtorrent']);
        $this->assertTrue(\pmssTorrentClientsStoreArchiveIsSafe($safe));
        symlink('rtorrent', $root.'/linked');
        $linked = $root.'/linked.tar.gz';
        $this->pmssCreateTarGzArchive($linked, $root, ['linked']);
        $this->assertFalse(\pmssTorrentClientsStoreArchiveIsSafe($linked));
    }

    public function testLinkedStoreRootIsRejectedBeforeInstall(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-linked-');
        mkdir($root.'/outside');
        symlink($root.'/outside', $root.'/store');
        $calls = 0;
        $results = \pmssTorrentClientsStoreConverge([$this->entry()], [
            'root' => $root.'/store',
            'free' => static function (): int { return 10 * 1024 * 1024 * 1024; },
            'size' => static function (): int { return 0; },
            'install' => static function () use (&$calls): bool { $calls++; return true; },
            'log' => static function (): void {},
        ]);
        $this->assertSame(['rtorrent/0.9.9' => 'failed'], $results);
        $this->assertSame(0, $calls);
    }

    public function testWritableStoreDirectoryIsRejectedBeforeInstall(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-writable-');
        $store = $root.'/store';
        mkdir($store.'/rtorrent/0.9.9', 0755, true);
        $calls = 0;
        foreach ([
            [$root, 0775], [$store, 0777],
            [$store.'/rtorrent', 0775], [$store.'/rtorrent/0.9.9', 0777],
        ] as [$path, $mode]) {
            chmod($path, $mode);
            $results = \pmssTorrentClientsStoreConverge([$this->entry()], [
                'root' => $store,
                'free' => static function (): int { return 10 * 1024 * 1024 * 1024; },
                'size' => static function (): int { return 0; },
                'install' => static function () use (&$calls): bool { $calls++; return true; },
                'log' => static function (): void {},
            ]);
            $this->assertSame(['rtorrent/0.9.9' => 'failed'], $results);
            chmod($path, 0755);
        }
        $this->assertSame(0, $calls);
        $this->assertTrue(\pmssTorrentClientsStorePathChainSafe($store, 'rtorrent', '0.9.9'));
    }

    public function testSuccessfulInstallKeepsOlderVersionAndSetsDefault(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-install-');
        $store = $root.'/pmss/clients';
        mkdir($store.'/rtorrent/old', 0755, true);
        file_put_contents($store.'/rtorrent/old/rtorrent', 'older');
        $entry = $this->entry();
        $result = \pmssTorrentClientsStoreConverge([$entry], [
            'root' => $store,
            'free' => static function (): int { return 10 * 1024 * 1024 * 1024; },
            'size' => static function (): int { return 10; },
            'install' => static function (array $item, string $binary): bool {
                return file_put_contents($binary, 'newer') === 5;
            },
            'log' => static function (): void {},
        ]);
        $this->assertSame(['rtorrent/0.9.9' => 'installed'], $result);
        $this->assertTrue(is_file($store.'/rtorrent/old/rtorrent'));
        $this->assertSame('0.9.9', readlink($store.'/rtorrent/default'));
        $this->assertSame(0755, fileperms($store.'/rtorrent/0.9.9/rtorrent') & 0777);
    }

    public function testConvergeRestoresTraverseAfterTenantSweep(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-sweep-');
        $store = $root.'/pmss/clients';
        // A matching pin keeps the second run on the already-installed fast path.
        $entry = $this->entry();
        $entry['sha256'] = hash('sha256', 'newer');
        $ops = [
            'root' => $store,
            'free' => static function (): int { return 10 * 1024 * 1024 * 1024; },
            'size' => static function (): int { return 10; },
            'install' => static function (array $item, string $binary): bool {
                return file_put_contents($binary, 'newer') === 5;
            },
            'log' => static function (): void {},
        ];
        $this->assertSame(['rtorrent/0.9.9' => 'installed'], \pmssTorrentClientsStoreConverge([$entry], $ops));
        // update-step2 hardens every depth-1 /home directory to 0700, dot-dirs included.
        chmod($root.'/pmss', 0700);
        $this->assertSame(['rtorrent/0.9.9' => 'installed'], \pmssTorrentClientsStoreConverge([$entry], $ops));
        clearstatcache();
        $this->assertSame(0755, fileperms($root.'/pmss') & 0777);
        $this->assertSame(0755, fileperms($store) & 0777);
    }
}
