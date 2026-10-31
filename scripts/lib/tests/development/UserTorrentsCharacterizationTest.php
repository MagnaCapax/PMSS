<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/userTorrents.php';

class UserTorrentsCharacterizationTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-user-torrents-characterization-']; }

    public function testMixedLegacyLayoutsCharacterization(): void
    {
        $home = $this->tempDir.'/home';

        // Keep the duplicate legacy Deluge and qBittorrent entries in the fixture tree.
        $this->pmssWriteFiles($home.'/fixtur01', array_fill_keys([
            'session/base.torrent', '.config/deluge/state/deluge-one.torrent',
            '.delugeSession/deluge-one.torrent', '.sessionDeluge/deluge-two.torrent',
            '.local/share/data/qBittorrent/BT_backup/qbit-one.torrent',
            '.local/share/data/qBittorrent/BT_backup/qbit-one.fastresume',
            '.local/share/qBittorrent/BT_backup/qbit-two.torrent',
            '.config/qBittorrent/BT_backup/qbit-three.fastresume',
        ], 'test'));

        $this->assertSame([
            'rtorrent' => 1,
            'deluge' => 2,
            'qbittorrent' => 3,
            'total' => 6,
        ], \pmssUserTorrentsCountForUser($home, 'fixtur01'));
    }
}
