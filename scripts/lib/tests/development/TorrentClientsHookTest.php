<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TorrentClientsHookTest extends TestCase
{
    public function testHookLoadsStoresAndPublishesWithoutHostSetup(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/torrentClientStore.php';
        $this->assertTrue(include_once $path);
        $events = [];
        $catalog = [['client' => 'rtorrent', 'version' => '0.16.25']];
        \pmssTorrentClientStoreRun(self::pmssMakeCallbacks(['load' => $catalog], $events) + [
            'store' => static function (array $entries) use (&$events): void { $events[] = ['store', $entries]; },
            'project' => static function (array $entries) use (&$events): bool { $events[] = ['project', $entries]; return true; },
        ]);
        $this->assertSame(['load', ['store', $catalog], ['project', $catalog]], $events);
    }

    public function testStoreProjectionAndLinksConvergeWithoutHostSetup(): void
    {
        require_once dirname(__DIR__, 2).'/torrentClients/store.php';
        require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
        require_once dirname(__DIR__, 2).'/torrentClients/userLinks.php';
        $root = $this->pmssMakeTempDir('pmss-clients-writers-');
        $events = [];
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $root.'/store'], function () use ($root, &$events): void {
            $entry = [
                'client' => 'rtorrent', 'version' => '0.9.9', 'kind' => 'static',
                'url' => ['https://example.invalid/client'], 'sha256' => hash('sha256', 'fixture'),
                'disabled' => false,
            ];
            $result = \pmssTorrentClientsStoreConverge([$entry], [
                'root' => $root.'/store',
                'install' => static function (array $row, string $binary) use (&$events): bool {
                    $events[] = 'download'; return file_put_contents($binary, 'fixture') === 7;
                },
            ] + self::pmssMakeCallbacks(['free' => 10 * 1024 ** 3, 'size' => 0]));
            $this->assertSame(['rtorrent/0.9.9' => 'installed'], $result);
            $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $root.'/public/catalog.json'));
            $this->assertSame('0.9.9', \pmssTorrentClientsProjectionVersionsRead($root.'/public/catalog.json')[0]['version']);
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $root.'/t', [
                'homeRoot' => $root,
                'root' => $root.'/store',
                'catalog' => dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json',
                'run' => static function () use (&$events): bool { $events[] = 'link'; return true; },
            ]));
            $this->assertSame(['download', 'link', 'link', 'link'], $events);
        });
    }

    public function testRuntimeTreesContainNoHostOptInMarker(): void
    {
        $root = dirname(__DIR__, 4);
        foreach (['etc', 'scripts'] as $tree) {
            $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root.'/'.$tree, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    return !$file->isLink() && !in_array($file->getFilename(), ['tests', 'docs'], true);
                }
            ));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() === 'md') continue;
                $content = file_get_contents($file->getPathname());
                $this->assertTrue(is_string($content), $file->getPathname());
                $this->assertStringNotContainsString('torrentClients.enabled', $content, $file->getPathname());
            }
        }
    }
}
