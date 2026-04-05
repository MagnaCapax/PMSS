<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/userTorrents.php';

class UserTorrentsCharacterizationTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-user-torrents-characterization-');
    }

    public function testMixedLegacyLayoutsCharacterization(): void
    {
        $home = $this->tempDir.'/home';

        $this->pmssWriteFile($home.'/alice/session/base.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.config/deluge/state/deluge-one.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.delugeSession/deluge-one.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.sessionDeluge/deluge-two.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/data/qBittorrent/BT_backup/qbit-one.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/data/qBittorrent/BT_backup/qbit-one.fastresume', 'test');
        $this->pmssWriteFile($home.'/alice/.local/share/qBittorrent/BT_backup/qbit-two.torrent', 'test');
        $this->pmssWriteFile($home.'/alice/.config/qBittorrent/BT_backup/qbit-three.fastresume', 'test');

        $this->assertSame([
            'rtorrent' => 1,
            'deluge' => 2,
            'qbittorrent' => 3,
            'total' => 6,
        ], \pmssUserTorrentsCountForUser($home, 'alice'));
    }
}
