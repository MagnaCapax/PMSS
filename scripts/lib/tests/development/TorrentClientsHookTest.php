<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TorrentClientsHookTest extends TestCase
{
    public function testAbsentMarkerMakesIncludeAndRunInert(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/torrentClientStore.php';
        $this->assertTrue(include_once $path);
        $events = [];
        $root = $this->pmssMakeTempDir('pmss-clients-inert-');
        $throw = static function () use (&$events): void { $events[] = 'side effect'; throw new \RuntimeException('called'); };
        \pmssTorrentClientStoreRun($root.'/absent-marker', [
            'load' => $throw, 'store' => $throw, 'project' => $throw,
        ]);
        $this->assertSame([], $events);
        $this->assertSame([], glob($root.'/*'));
    }

    public function testAbsentMarkerKeepsEveryWriterInert(): void
    {
        require_once dirname(__DIR__, 2).'/torrentClients/store.php';
        require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
        require_once dirname(__DIR__, 2).'/torrentClients/userLinks.php';
        $root = $this->pmssMakeTempDir('pmss-clients-writers-inert-');
        putenv('PMSS_TORRENT_CLIENTS_TEST_MARKER='.$root.'/absent');
        $events = [];
        try {
            $entry = [
                'client' => 'rtorrent', 'version' => '0.9.9', 'kind' => 'static',
                'url' => ['https://example.invalid/client'], 'sha256' => str_repeat('a', 64),
                'disabled' => false,
            ];
            $result = \pmssTorrentClientsStoreConverge([$entry], [
                'root' => $root.'/store',
                'install' => static function () use (&$events): bool { $events[] = 'download'; return true; },
            ]);
            $this->assertSame([], $result);
            $this->assertFalse(\pmssTorrentClientsProjectionWrite([$entry], $root.'/public/catalog.json'));
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $root.'/t', [
                'homeRoot' => $root,
                'root' => $root.'/store',
                'run' => static function () use (&$events): bool { $events[] = 'link'; return true; },
            ]));
            $this->assertSame([], $events);
            $this->assertSame([], glob($root.'/*'));
        } finally {
            putenv('PMSS_TORRENT_CLIENTS_TEST_MARKER');
        }
    }
}
