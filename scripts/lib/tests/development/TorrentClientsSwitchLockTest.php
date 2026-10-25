<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsVersion.php';

/** The switch lock tells a busy switch from a lock that cannot be created. */
final class TorrentClientsSwitchLockTest extends TestCase
{
    public function testLockOpensThenReportsABusySwitch(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-switch-lock-').'/torrentClients';
        [$lock, $refused] = \pmssConfigureTorrentClientLockOpen($dir, 'rtorrent', 'rtorrent');
        $this->assertTrue(is_resource($lock));
        $this->assertSame(null, $refused);
        [$second, $busy] = \pmssConfigureTorrentClientLockOpen($dir, 'rtorrent', 'rtorrent');
        $this->assertSame(null, $second);
        $this->assertSame(['ok' => false, 'status' => 409, 'message' => 'A rtorrent switch is already running.'], $busy);
        fclose($lock);
    }

    public function testUncreatableLockDirectoryIsNotReportedAsBusy(): void
    {
        $parent = $this->pmssMakeTempDir('pmss-switch-lock-ro-');
        $this->pmssWriteFile($parent.'/blocker', 'a file where the directory should be');
        [$lock, $refused] = \pmssConfigureTorrentClientLockOpen($parent.'/blocker/torrentClients', 'qbittorrent', 'qBittorrent');
        $this->assertSame(null, $lock);
        $this->assertSame(503, $refused['status']);
        $this->assertSame('The switch lock is unavailable.', $refused['message']);
    }
}
