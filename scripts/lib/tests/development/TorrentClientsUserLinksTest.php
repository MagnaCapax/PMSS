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
        mkdir($home, 0755);
        mkdir($store.'/5.1.0', 0755, true);
        file_put_contents($store.'/5.1.0/qbittorrent-nox', 'binary');
        symlink('5.1.0', $store.'/default');
        $ops = ['root' => $root.'/store', 'homeRoot' => $root, 'run' => $this->pmssAccountPathCommandRunner(),
            'catalog' => dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json'];
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $versionLink = $home.'/bin/qbittorrent/5.1.0';
        $defaultLink = $home.'/bin/qbittorrent/default';
        $this->assertSame($store.'/5.1.0', readlink($versionLink));
        $this->assertFalse(is_link($defaultLink));
        mkdir($home.'/bin/qbittorrent/custom');
        file_put_contents($home.'/bin/qbittorrent/custom/qbittorrent-nox', 'custom');
        symlink('custom', $defaultLink);
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $this->assertSame('custom', readlink($defaultLink));
        $this->assertSame($home.'/bin/qbittorrent/custom/qbittorrent-nox', \pmssTorrentClientResolve($home, 'qbittorrent'));
    }

    public function testResolverRejectsEscapingOrLinkedDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-clients-resolve-');
        $home = $root.'/t';
        mkdir($home.'/bin/qbittorrent', 0755, true);
        $default = $home.'/bin/qbittorrent/default';
        symlink('../../other', $default);
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'qbittorrent'));
        unlink($default);
        symlink('linked', $default);
        mkdir($root.'/other');
        symlink($root.'/other', $home.'/bin/qbittorrent/linked');
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'qbittorrent'));
    }
}
