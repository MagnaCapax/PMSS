<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/userTorrents.php';

class UserTorrentsCountTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-usertorrents-']; }

    private function homeDir(): string { return $this->tempDir.'/home'; }

    public function testEntryNamesInsideHomeAreCounted(): void
    {
        $directory = $this->homeDir().'/fixtur01/session';
        $this->pmssWriteFiles($directory, ['a.torrent' => 'test', 'b.torrent' => 'test']);
        $this->assertSame(['a', 'b'], \pmssUserTorrentsEntryNames($directory, '*.torrent', realpath($this->homeDir().'/fixtur01')));

        $counts = \pmssUserTorrentsCountForUser($this->homeDir(), 'fixtur01');
        $this->assertEquals(2, $counts['rtorrent']);
        $this->assertEquals(2, $counts['total']);
    }

    public function testEntryNamesSkipDirectoryWithSymlinkedComponentOutsideHome(): void
    {
        $home = $this->homeDir().'/fixtur01';
        $outside = $this->tempDir.'/outside';
        $this->pmssWriteFile($outside.'/session/foreign.torrent', 'test');
        $this->pmssEnsureDir($home);
        $this->assertTrue(symlink($outside, $home.'/linked'));

        $this->assertSame([], \pmssUserTorrentsEntryNames($home.'/linked/session', '*.torrent', realpath($home)));
    }

    public function testCountsAllClientsAndTotals(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFiles($home.'/fixtur01', array_fill_keys([
            // rTorrent, Deluge state, then qBittorrent backups in their original order.
            'session/a.torrent', 'session/b.torrent', 'session/c.torrent',
            '.config/deluge/state/111.torrent', '.config/deluge/state/222.torrent',
            '.local/share/qBittorrent/BT_backup/x.torrent', '.local/share/qBittorrent/BT_backup/y.torrent',
            '.local/share/qBittorrent/BT_backup/z.torrent', '.local/share/qBittorrent/BT_backup/w.torrent',
        ], 'test'));

        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(3, $counts['rtorrent']);
        $this->assertEquals(2, $counts['deluge']);
        $this->assertEquals(4, $counts['qbittorrent']);
        $this->assertEquals(9, $counts['total']);
    }

    public function testDedupesQbittorrentTorrentAndFastresumeByBaseName(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFiles($home.'/fixtur01/.local/share/qBittorrent/BT_backup', ['abc.torrent' => 'test', 'abc.fastresume' => 'test']);
        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(1, $counts['qbittorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testDedupesDelugeAcrossMultipleDirs(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFiles($home.'/fixtur01', ['.config/deluge/state/same.torrent' => 'test', '.delugeSession/same.torrent' => 'test']);
        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(1, $counts['deluge']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testIgnoresEntriesWithEmptyTorrentBaseName(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFiles($home.'/fixtur01/session', ['.torrent' => 'test', 'good.torrent' => 'test']);

        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');

        $this->assertEquals(1, $counts['rtorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testMissingDirsReturnZeros(): void
    {
        $home = $this->homeDir();
        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(0, $counts['rtorrent']);
        $this->assertEquals(0, $counts['deluge']);
        $this->assertEquals(0, $counts['qbittorrent']);
        $this->assertEquals(0, $counts['total']);
    }

    public function testSymlinkedSessionOutsideHomeIsSkipped(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFile($home.'/fixtur02/session/foreign.torrent', 'test');
        $this->pmssEnsureDir($home.'/fixtur01');
        $this->assertTrue(symlink($home.'/fixtur02/session', $home.'/fixtur01/session'));

        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(0, $counts['rtorrent']);
        $this->assertEquals(0, $counts['total']);
    }

    public function testSymlinkedConfigParentOutsideHomeIsSkipped(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFiles($home, ['fixtur02/.config/deluge/state/foreign.torrent' => 'test', 'fixtur01/session/own.torrent' => 'test']);
        $this->assertTrue(symlink($home.'/fixtur02/.config', $home.'/fixtur01/.config'));

        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(0, $counts['deluge']);
        $this->assertEquals(1, $counts['rtorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testSymlinkedTorrentEntryIsSkipped(): void
    {
        $home = $this->homeDir();
        $foreign = $this->pmssWriteFile($home.'/fixtur02/session/foreign.torrent', 'test');
        $this->pmssWriteFile($home.'/fixtur01/session/own.torrent', 'test');
        $this->assertTrue(symlink($foreign, $home.'/fixtur01/session/foreign.torrent'));

        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(1, $counts['rtorrent']);
        $this->assertEquals(1, $counts['total']);
    }

    public function testSymlinkedDirectoryWithinHomeStillCounts(): void
    {
        $home = $this->homeDir();
        $this->pmssWriteFile($home.'/fixtur01/private-session/own.torrent', 'test');
        $this->assertTrue(symlink($home.'/fixtur01/private-session', $home.'/fixtur01/session'));

        $counts = \pmssUserTorrentsCountForUser($home, 'fixtur01');
        $this->assertEquals(1, $counts['rtorrent']);
        $this->assertEquals(1, $counts['total']);
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
