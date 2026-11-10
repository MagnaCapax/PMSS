<?php
namespace PMSS\Tests\TorrentStoreInstallFixture;

/** Recording dependencies keep the real installation dispatcher away from host artifacts. */
function pmssTorrentClientsDelugeVenvInstall($entry, $directory): bool
{
    $GLOBALS['pmssStoreInstall']['events'][] = ['deluge', $entry, $directory];
    return $GLOBALS['pmssStoreInstall']['result'];
}
function pmssTorrentClientsStoreVenvInstall($entry, $binary): bool
{
    $GLOBALS['pmssStoreInstall']['events'][] = ['archive', $entry, $binary];
    return $GLOBALS['pmssStoreInstall']['result'];
}
function pmssInstallPinnedRemoteBinary($label, $url, $sha, $binary, $required): void
{
    $GLOBALS['pmssStoreInstall']['events'][] = ['download', $label, $url, $sha, $binary, $required];
    if (isset($GLOBALS['pmssStoreInstall']['throwable'])) throw $GLOBALS['pmssStoreInstall']['throwable'];
    $GLOBALS['pmssStoreInstall']['hash'] = $GLOBALS['pmssStoreInstall']['mirrors'][$url];
}
function is_file($path): bool
{
    $GLOBALS['pmssStoreInstall']['events'][] = ['file', $path];
    return $GLOBALS['pmssStoreInstall']['hash'] !== false;
}
function hash_file($algorithm, $path): string
{
    $GLOBALS['pmssStoreInstall']['events'][] = ['hash', $algorithm, $path];
    return $GLOBALS['pmssStoreInstall']['hash'];
}

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../testing/filesystem.php';
require_once __DIR__.'/../../torrentClients/storeInstall.php';

/** Freeze the former store.php callback's dispatch, mirror order and pin behavior. */
final class TorrentClientsStoreInstallCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists(__NAMESPACE__.'\\TorrentStoreInstallFixture\\pmssTorrentClientsStoreInstall')) {
            eval(\pmssTestingFunctionExport('pmssTorrentClientsStoreInstall', __NAMESPACE__.'\\TorrentStoreInstallFixture'));
        }
        $GLOBALS['pmssStoreInstall'] = ['events' => [], 'result' => false, 'hash' => false, 'mirrors' => []];
    }

    protected function tearDown(): void { unset($GLOBALS['pmssStoreInstall']); }

    public function testVenvDispatchPreservesBackendArgumentsAndResults(): void
    {
        foreach (['deluge' => 'deluge', 'rtorrent' => 'archive'] as $client => $backend) {
            foreach ([false, true] as $result) {
                $entry = ['client' => $client, 'kind' => 'venv', 'version' => 'fixture'];
                $binary = '/fixture/clients/'.$client.'/fixture/'.($client === 'deluge' ? 'bin/deluged' : 'rtorrent');
                $GLOBALS['pmssStoreInstall']['events'] = [];
                $GLOBALS['pmssStoreInstall']['result'] = $result;
                $this->assertSame($result, TorrentStoreInstallFixture\pmssTorrentClientsStoreInstall($entry, $binary));
                $this->assertSame([[$backend, $entry, $client === 'deluge' ? dirname(dirname($binary)) : $binary]],
                    $GLOBALS['pmssStoreInstall']['events']);
            }
        }
    }

    public function testStaticMirrorTracesPreserveMissingMismatchSuccessAndExhaustion(): void
    {
        $binary = '/fixture/clients/rtorrent/fixture/rtorrent';
        $entry = ['client' => 'rtorrent', 'version' => 'fixture', 'kind' => 'static',
            'url' => ['https://example.invalid/first', 'https://example.invalid/second'], 'sha256' => str_repeat('A', 64)];
        foreach ([[false, str_repeat('a', 64)], [str_repeat('b', 64), str_repeat('a', 64)],
            [str_repeat('a', 64), false], [false, false]] as $hashes) {
            $GLOBALS['pmssStoreInstall']['events'] = [];
            $GLOBALS['pmssStoreInstall']['mirrors'] = array_combine($entry['url'], $hashes);
            $expected = [];
            $installed = false;
            foreach ($entry['url'] as $index => $url) {
                $expected[] = ['download', 'rtorrent fixture', $url, $entry['sha256'], $binary, true];
                $expected[] = ['file', $binary];
                if ($hashes[$index] !== false) $expected[] = ['hash', 'sha256', $binary];
                if ($hashes[$index] === str_repeat('a', 64)) { $installed = true; break; }
            }
            $this->assertSame($installed, TorrentStoreInstallFixture\pmssTorrentClientsStoreInstall($entry, $binary));
            $this->assertSame($expected, $GLOBALS['pmssStoreInstall']['events']);
        }
        $entry['url'] = [];
        $GLOBALS['pmssStoreInstall']['events'] = [];
        $this->assertFalse(TorrentStoreInstallFixture\pmssTorrentClientsStoreInstall($entry, $binary));
        $this->assertSame([], $GLOBALS['pmssStoreInstall']['events']);
    }

    public function testDownloadThrowablePropagatesBeforeAnyFileProbeOrNextMirror(): void
    {
        foreach ([new \RuntimeException('fixture'), new \Error('fixture')] as $error) {
            $GLOBALS['pmssStoreInstall']['events'] = [];
            $GLOBALS['pmssStoreInstall']['throwable'] = $error;
            $entry = ['client' => 'rtorrent', 'version' => 'fixture', 'kind' => 'static',
                'url' => ['https://example.invalid/first', 'https://example.invalid/second'], 'sha256' => str_repeat('a', 64)];
            $caught = null;
            try { TorrentStoreInstallFixture\pmssTorrentClientsStoreInstall($entry, '/fixture/binary'); }
            catch (\Throwable $thrown) { $caught = $thrown; }
            $this->assertSame($error, $caught);
            $this->assertSame([['download', 'rtorrent fixture', $entry['url'][0], $entry['sha256'], '/fixture/binary', true]],
                $GLOBALS['pmssStoreInstall']['events']);
        }
    }
}
