<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/userTorrents.php';

class UserTorrentsCountTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    private function setUpTempDir(): void
    {
        $base = sys_get_temp_dir().'/pmss-usertorrents-tests';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $this->tempDir = $base.'/run-'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    private function tearDownTempDir(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            if ($path->isDir()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }
        @rmdir($this->tempDir);
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
        $this->setUpTempDir();
        try {
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
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDedupesQbittorrentTorrentAndFastresumeByBaseName(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->homeDir();
            $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/abc.torrent');
            $this->makeFile($home.'/alice/.local/share/qBittorrent/BT_backup/abc.fastresume');
            $counts = \pmssUserTorrentsCountForUser($home, 'alice');
            $this->assertEquals(1, $counts['qbittorrent']);
            $this->assertEquals(1, $counts['total']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDedupesDelugeAcrossMultipleDirs(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->homeDir();
            $this->makeFile($home.'/alice/.config/deluge/state/same.torrent');
            $this->makeFile($home.'/alice/.delugeSession/same.torrent');
            $counts = \pmssUserTorrentsCountForUser($home, 'alice');
            $this->assertEquals(1, $counts['deluge']);
            $this->assertEquals(1, $counts['total']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testMissingDirsReturnZeros(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->homeDir();
            $counts = \pmssUserTorrentsCountForUser($home, 'alice');
            $this->assertEquals(0, $counts['rtorrent']);
            $this->assertEquals(0, $counts['deluge']);
            $this->assertEquals(0, $counts['qbittorrent']);
            $this->assertEquals(0, $counts['total']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testInvalidUsernameReturnsZeros(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->homeDir();
            $counts = \pmssUserTorrentsCountForUser($home, '../evil');
            $this->assertEquals(0, $counts['total']);
        } finally {
            $this->tearDownTempDir();
        }
    }
}

