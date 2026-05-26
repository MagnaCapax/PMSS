<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/userTorrents.php';

class UserTorrentsCountTest extends TestCase
{
    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-usertorrents-');
    }

    private function homeDir(): string { return $this->tempDir.'/home'; }

    public function testCountsAllClientsAndTotals(): void
    {
        $home = $this->homeDir();
        // rTorrent
        $this->pmssWriteFile($home.'/alice/session/a.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/session/b.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/session/c.torrent', 'test');

        // Deluge (state)
        $this->pmssWriteFile($home.'/alice/.config/deluge/state/111.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.config/deluge/state/222.torrent', 'test');

        // qBittorrent (BT_backup)
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/x.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/y.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/z.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/w.torrent', 'test');

        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(3, $counts['rtorrent']);
        $this->assertEquals(2, $counts['deluge']);
        $this->assertEquals(4, $counts['qbittorrent']);
        $this->assertEquals(9, $counts['total']);
    }

    public function testDedupesQbittorrentTorrentAndFastresumeByBaseName(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/abc.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/abc.fastresume', 'test');
        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(1, $counts['qbittorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testDedupesDelugeAcrossMultipleDirs(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFile($home.'/alice/.config/deluge/state/same.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.delugeSession/same.torrent', 'test');
        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(1, $counts['deluge']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testIgnoresEntriesWithEmptyTorrentBaseName(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFile($home.'/alice/session/.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/session/good.torrent', 'test');

        $counts = \pmssUserTorrentsCountForUser($home, 'alice');

        $this->assertEquals(1, $counts['rtorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testMissingDirsReturnZeros(): void
    {
        $home = $this->homeDir();
        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(0, $counts['rtorrent']);
        $this->assertEquals(0, $counts['deluge']);
        $this->assertEquals(0, $counts['qbittorrent']);
        $this->assertEquals(0, $counts['total']);
    }

    public function testInvalidUsernameReturnsZeros(): void
    {
        $home = $this->homeDir();
        $counts = \pmssUserTorrentsCountForUser($home, '../evil');
        $this->assertEquals(0, $counts['total']);
    }

    public function testHelpOutputRemainsStable(): void
    {
        $output = $this->pmssRunPhpScript(dirname(__DIR__, 3).'/userTorrents.php', ['--help'], [], '');

        $this->assertTrue(is_string($output));

        $this->assertEquals(
            "Usage: userTorrents.php [--by-client]\n\n"
            ."Options:\n"
            ."  --by-client  Show per-client breakdown (rtorrent/deluge/qbittorrent).\n"
            ."  --help       Show this help.\n\n",
            $output
        );
    }
}
