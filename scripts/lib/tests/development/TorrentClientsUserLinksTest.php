<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/userLinks.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

final class TorrentClientsUserLinksTest extends TestCase
{
    public function testLinksConvergeIdempotentlyWithoutOverridingDefault(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-links-');
        $home = $root.'/t';
        $store = $root.'/store/qbittorrent';
        $this->pmssEnsureDir($home);
        $this->pmssWriteRelativeFile($store, '5.1.0/qbittorrent-nox', 'binary');
        $this->pmssWriteSymlinks($store, ['default' => '5.1.0']);
        $ops = ['root' => $root.'/store', 'homeRoot' => $root, 'run' => $this->pmssAccountPathCommandRunner(),
            'catalog' => dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json'];
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $versionLink = $home.'/bin/qbittorrent/5.1.0';
        $defaultLink = $home.'/bin/qbittorrent/default';
        $this->assertSame($store.'/5.1.0', readlink($versionLink));
        $this->assertFalse(is_link($defaultLink));
        $this->pmssWriteRelativeFile($home, 'bin/qbittorrent/custom/qbittorrent-nox', 'custom', 0777);
        $this->pmssWriteSymlinks($home, ['bin/qbittorrent/default' => 'custom']);
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $this->assertSame('custom', readlink($defaultLink));
        $this->assertSame($home.'/bin/qbittorrent/custom/qbittorrent-nox', \pmssTorrentClientResolve($home, 'qbittorrent'));
    }

    public function testResolverRejectsEscapingOrLinkedDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-resolve-');
        $home = $root.'/t';
        $default = $home.'/bin/qbittorrent/default';
        $this->pmssWriteSymlinks($home, ['bin/qbittorrent/default' => '../../other']);
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'qbittorrent'));
        unlink($default);
        $this->pmssWriteSymlinks($home, ['bin/qbittorrent/default' => 'linked']);
        $this->pmssEnsureDir($root.'/other', 0777);
        $this->pmssWriteSymlinks($home, ['bin/qbittorrent/linked' => $root.'/other']);
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'qbittorrent'));
    }
}
