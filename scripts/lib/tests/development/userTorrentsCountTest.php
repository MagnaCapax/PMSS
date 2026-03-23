<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/userTorrents.php';

class UserTorrentsCountTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-usertorrents-');
    }

    protected function tearDown(): void
    {
        $this->tempDir = '';
    }

    private function homeDir(): string
    {
        return $this->tempDir.'/home';
    }

    private function makeFile(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, 'test');
    }

    public function testCountsAllClientsAndTotals(): void
    {
        $home = $this->homeDir();
        // rTorrent
        $this->makeFile($home.'/alice/session/a.torrent');
        $this->makeFile($home.'/alice/session/b.torrent');
        $this->makeFile($home.'/alice/session/c.torrent');

        // Deluge (state)
        $this->makeFile($home.'/alice/.config/deluge/state/111.torrent');
        $this->makeFile($home.'/alice/.config/deluge/state/222.torrent');

        // qBittorrent (BT_backup)
        $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/x.torrent');
        $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/y.torrent');
        $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/z.torrent');
        $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/w.torrent');

        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(3, $counts['rtorrent']);
        $this->assertEquals(2, $counts['deluge']);
        $this->assertEquals(4, $counts['qbittorrent']);
        $this->assertEquals(9, $counts['total']);
    }

    public function testDedupesQbittorrentTorrentAndFastresumeByBaseName(): void
    {
        $home = $this->homeDir();
        $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/abc.torrent');
        $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/abc.fastresume');
        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(1, $counts['qbittorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testDedupesDelugeAcrossMultipleDirs(): void
    {
        $home = $this->homeDir();
        $this->makeFile($home.'/alice/.config/deluge/state/same.torrent');
        $this->makeFile($home.'/alice/.delugeSession/same.torrent');
        $counts = \pmssUserTorrentsCountForUser($home, 'alice');
        $this->assertEquals(1, $counts['deluge']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testIgnoresEntriesWithEmptyTorrentBaseName(): void
    {
        $home = $this->homeDir();
        $this->makeFile($home.'/alice/session/.torrent');
        $this->makeFile($home.'/alice/session/good.torrent');

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
        $output = shell_exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 3).'/userTorrents.php').' --help'
        );

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
