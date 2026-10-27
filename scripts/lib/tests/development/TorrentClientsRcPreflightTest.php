<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** Diagnose known rc failures without invoking a daemon or changing customer settings. */
final class TorrentClientsRcPreflightTest extends TestCase
{
    public function testBareRemovedGroupsReportThePhysicalLine(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rc-check-');
        foreach (array('0.16.25', '0.16.26') as $version) {
            foreach (array('socket_critical', 'storage_critical') as $group) {
                foreach (array('', '"', "'") as $quote) {
                    $raw = "# customer settings\r\nthrottle.up = 100\r\n\tlog.add_output = "
                        .$quote.$group.$quote.", rtorrent.log # keep\r\n";
                    $this->pmssWriteFile($home.'/.rtorrent.rc.custom', $raw);
                    $message = \pmssConfigureTorrentClientRtorrentRcCheck($home, $version);
                    if ($group === 'storage_critical' && $version === '0.16.25') {
                        $this->assertSame(null, $message);
                    } else {
                        $this->assertStringContainsString('line 3', $message);
                        $this->assertStringContainsString('unavailable log group '.$group, $message);
                        $this->assertStringContainsString('Wrap this log.add_output command in catch', $message);
                    }
                    $this->assertSame($raw, file_get_contents($home.'/.rtorrent.rc.custom'));
                }
            }
        }
    }

    public function testCommentsWrappersAndOtherCommandsAreNotRefused(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rc-check-');
        $cases = array('', '# log.add_output = socket_critical,rtorrent.log',
            'catch = {"log.add_output=socket_critical,rtorrent.log"}',
            'catch = {"log.add_output=storage_critical,rtorrent.log"}',
            'log.add_output = "notice", "rtorrent.log"',
            'log.add_output = socket_critical_custom,rtorrent.log',
            'schedule2 = later,5,0,"log.add_output=socket_critical,rtorrent.log"',
            file_get_contents(dirname(__DIR__, 4).'/etc/skel/.rtorrent.rc.custom'));
        foreach ($cases as $raw) {
            $this->pmssWriteFile($home.'/.rtorrent.rc.custom', $raw);
            $this->assertSame(null, \pmssConfigureTorrentClientRtorrentRcCheck($home, '0.16.26'));
        }
    }

    public function testOtherReleasesKeepTheirExistingSwitchPath(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rc-check-');
        $this->pmssWriteFile($home.'/.rtorrent.rc.custom', 'log.add_output=socket_critical,rtorrent.log');
        foreach (array('system', 'custom', '0.9.8-r16', '0.9.8-stickz7.2') as $version)
            $this->assertSame(null, \pmssConfigureTorrentClientRtorrentRcCheck($home, $version));
    }

    public function testMissingAndUnsafeFilesRetainBestEffortBehavior(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rc-check-');
        $path = $home.'/.rtorrent.rc.custom';
        $this->assertSame(null, \pmssConfigureTorrentClientRtorrentRcCheck($home, '0.16.26'));
        $target = $this->pmssWriteFile($home.'/target', 'log.add_output=socket_critical,rtorrent.log');
        $this->pmssCreateSymlinkOrSkip($target, $path);
        $this->assertSame(null, \pmssConfigureTorrentClientRtorrentRcCheck($home, '0.16.26'));
        unlink($path);
        $this->pmssWriteFile($path, str_repeat('#', 262145));
        $this->assertSame(null, \pmssConfigureTorrentClientRtorrentRcCheck($home, '0.16.26'));
    }

    public function testRefusalPrecedesBackupAndStopAndReleasesTheLock(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rc-switch-');
        $this->pmssEnsureDir($home.'/bin/rtorrent');
        $this->pmssCreateSymlinkOrSkip('/home/.pmss/clients/rtorrent/0.9.8-r16', $home.'/bin/rtorrent/default');
        $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
        foreach (array('lastRun', 'lastgood') as $suffix)
            $this->pmssWriteFile($dir.'/rtorrent.'.$suffix, "0.9.8-r16\n");
        $raw = "throttle.up = 100\nlog.add_output = socket_critical,rtorrent.log\n";
        $this->pmssWriteFile($home.'/.rtorrent.rc.custom', $raw);
        $events = array();
        $ops = array('versions' => array('0.16.26' => array('kind' => 'static')),
            'resolve' => static function () { return '/fixture/rtorrent'; })
            + $this->pmssMakeEventCallbacks($events,
                ['backup' => ['ok' => true], 'stop' => true, 'repoint' => true, 'start' => true]);
        $request = array('client' => 'rtorrent', 'version' => '0.16.26');
        $result = \pmssConfigureTorrentClientOtherSwitch($home, $request, $ops);
        $this->assertSame(409, $result['status']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('line 2', $result['message']);
        $this->assertSame(array(), $events);
        $this->assertSame($raw, file_get_contents($home.'/.rtorrent.rc.custom'));
        $this->assertSame('/home/.pmss/clients/rtorrent/0.9.8-r16', readlink($home.'/bin/rtorrent/default'));
        foreach (array('lastRun', 'lastgood') as $suffix)
            $this->assertSame("0.9.8-r16\n", file_get_contents($dir.'/rtorrent.'.$suffix));
        $this->assertSame($result['message']."\n", file_get_contents($dir.'/rtorrent.notice'));
        $lock = fopen($dir.'/rtorrent.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
        $this->pmssWriteFile($home.'/.rtorrent.rc.custom', 'catch = {"log.add_output=socket_critical,rtorrent.log"}');
        $this->assertTrue(\pmssConfigureTorrentClientOtherSwitch($home, $request, $ops)['ok']);
        $this->assertSame(array('backup', 'stop', 'repoint', 'start'), $events);
    }
}
