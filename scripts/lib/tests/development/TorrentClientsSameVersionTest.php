<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** No live daemons: record mutations and inject the observed native build under the real lock. */
final class TorrentClientsSameVersionTest extends TestCase
{
    private function fixture(string $client, string $selected, string $running, array &$events): array
    {
        $home = $this->pmssMakeTempDir('pmss-same-version-');
        $this->pmssEnsureDir($home.'/bin/'.$client);
        if ($selected !== 'system') $this->pmssCreateSymlinkOrSkip(
            '/home/.pmss/clients/'.$client.'/'.$selected, $home.'/bin/'.$client.'/default');
        $entry = array('kind' => $selected === 'system' ? 'system' : ($client === 'deluge' ? 'venv' : 'static'),
            'rpcUnixSocket' => true);
        $record = static function ($event) use (&$events): bool { $events[] = $event; return true; };
        $ops = array('versions' => array($selected => $entry),
            'resolve' => static function () { return '/fixture/binary'; },
            'runningVersion' => function () use ($home, $client, $running) {
                $other = fopen($home.'/.config/pmss/torrentClients/'.$client.'.lock', 'c');
                $this->assertFalse(flock($other, LOCK_EX | LOCK_NB), 'Native build checked under lock');
                fclose($other);
                return $running;
            },
            'backup' => static function () use ($record): array { return array('ok' => $record('backup')); },
            'stop' => static function () use ($record): bool { return $record('stop'); },
            'repoint' => static function () use ($record): bool { return $record('repoint'); },
            'restore' => static function (): bool { return true; },
            'start' => static function () use ($record): bool { return $record('start'); });
        return array($home, array('client' => $client, 'version' => $selected), $ops);
    }

    private function apply($home, $request, $ops): array
    {
        return $request['client'] === 'qbittorrent'
            ? \pmssConfigureTorrentClientSwitch($home, $request, $ops)
            : \pmssConfigureTorrentClientOtherSwitch($home, $request, $ops);
    }

    public function testSameRunningSelectionDoesNotMutateOrCreateState(): void
    {
        $cases = array('qbittorrent' => array('system', '5.1.4-lt2.0', 'custom'),
            'deluge' => array('system', '2.2.0', 'custom'), 'transmission' => array('4.1.3'),
            'rtorrent' => array('system', '0.16.25', 'custom'));
        $labels = array('qbittorrent' => 'qBittorrent', 'deluge' => 'Deluge',
            'transmission' => 'Transmission', 'rtorrent' => 'rTorrent');
        foreach ($cases as $client => $versions) foreach ($versions as $version) {
            $events = array();
            list($home, $request, $ops) = $this->fixture($client, $version, $version, $events);
            $before = @readlink($home.'/bin/'.$client.'/default');
            $result = $this->apply($home, $request, $ops);
            $this->assertSame(array('ok' => true, 'status' => 200, 'message' => $labels[$client]
                .' is already running '.\pmssConfigureTorrentClientVersionLabel($version).'; nothing changed.'), $result);
            $this->assertSame(array(), $events);
            $this->assertSame($before, @readlink($home.'/bin/'.$client.'/default'));
            $this->assertSame(array('.', '..', $client.'.lock'), scandir($home.'/.config/pmss/torrentClients'));
            $this->assertFalse(is_file($home.'/.qbittorrentEnable') || is_file($home.'/.delugeEnable'));
            $lock = fopen($home.'/.config/pmss/torrentClients/'.$client.'.lock', 'c');
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'No-op released its lock');
            fclose($lock);
        }
    }

    public function testNoOpPreservesExistingHistoryAndNoticeBytes(): void
    {
        foreach (array('qbittorrent', 'deluge', 'transmission', 'rtorrent') as $client) {
            $events = array();
            list($home, $request, $ops) = $this->fixture($client, '4.1.3', '4.1.3', $events);
            $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
            foreach (array('lastRun', 'lastgood', 'notice') as $suffix)
                $this->pmssWriteFile($dir.'/'.$client.'.'.$suffix, "kept\n");
            $this->assertTrue($this->apply($home, $request, $ops)['ok']);
            foreach (array('lastRun', 'lastgood', 'notice') as $suffix)
                $this->assertSame("kept\n", file_get_contents($dir.'/'.$client.'.'.$suffix));
            $this->assertSame(array(), $events);
        }
    }

    public function testStoppedDifferentAndFallbackBuildsStillRestartInOrder(): void
    {
        foreach (array('qbittorrent', 'deluge', 'transmission', 'rtorrent') as $client) {
            $states = array('not running', '3.0.0');
            if ($client === 'rtorrent') $states = array_merge($states, array('system',
                'system, because 4.1.3 kept stopping right after start',
                'system; 4.1.3 starts at the next rTorrent restart'));
            foreach ($states as $running) {
                $events = array();
                list($home, $request, $ops) = $this->fixture($client, '4.1.3', $running, $events);
                $this->assertTrue($this->apply($home, $request, $ops)['ok']);
                $this->assertSame(array('backup', 'stop', 'repoint', 'start'), $events);
            }
        }
    }

    public function testDifferentSelectionStillSwitchesEvenWhenRequestedBuildRuns(): void
    {
        foreach (array('qbittorrent', 'deluge', 'transmission', 'rtorrent') as $client) {
            $events = array();
            list($home, $request, $ops) = $this->fixture($client, '4.1.3', '4.2.0', $events);
            $ops['versions']['4.2.0'] = $ops['versions']['4.1.3'];
            $request['version'] = '4.2.0';
            $this->assertTrue($this->apply($home, $request, $ops)['ok']);
            $this->assertSame(array('backup', 'stop', 'repoint', 'start'), $events);
        }
    }

    public function testSameSelectionThatIsStoppedStillRefusesRecordedMajorDowngrade(): void
    {
        foreach (array('qbittorrent', 'deluge', 'transmission', 'rtorrent') as $client) {
            $events = array();
            list($home, $request, $ops) = $this->fixture($client, '4.1.3', 'not running', $events);
            $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
            $this->pmssWriteFile($dir.'/'.$client.'.lastRun', "5.0.0\n");
            $result = $this->apply($home, $request, $ops);
            $this->assertSame(409, $result['status']);
            $this->assertStringContainsString('switch refused', $result['message']);
            $this->assertSame(array(), $events);
            $this->assertSame("5.0.0\n", file_get_contents($dir.'/'.$client.'.lastRun'));
        }
    }

    public function testNoOpStillRequiresPasswordOfferedVersionFreeLockAndValidSelection(): void
    {
        foreach (array('qbittorrent', 'deluge', 'transmission', 'rtorrent') as $client) {
            $events = array();
            list($home, $request, $ops) = $this->fixture($client, '4.1.3', '4.1.3', $events);
            $this->assertSame(403, \pmssConfigureActionApply($home, 'torrentClientSwitch', $request, $ops)['status']);
            $unavailable = $ops;
            $unavailable['versions'] = array();
            $this->assertSame(400, $this->apply($home, $request, $unavailable)['status']);
            $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
            $lock = fopen($dir.'/'.$client.'.lock', 'c');
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try { $this->assertSame(409, $this->apply($home, $request, $ops)['status']); }
            finally { fclose($lock); }
            $ops['resolve'] = static function () { return null; };
            $this->assertSame(409, $this->apply($home, $request, $ops)['status']);
            $this->assertSame(array(), $events);
            $expected = array('.', '..', $client.'.lock');
            if ($client === 'qbittorrent') {
                $expected[] = $client.'.notice';
                $this->assertStringContainsString('current selection is invalid', file_get_contents($dir.'/'.$client.'.notice'));
            }
            $this->assertSame($expected, scandir($dir));
        }
    }
}
