<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** Keep client actions inside the existing password and rollback contract. */
final class TorrentClientsOtherSwitchTest extends TestCase
{
    private function home(string $client): string
    {
        $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-other-switch-').'/t');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $this->pmssEnsureDir($home.'/bin/'.$client);
        $hash = crypt('secret', '$6$rounds=5000$pmssswitch$');
        $this->pmssWriteFile($home.'/.lighttpd/.htpasswd', 't:'.$hash."\n");
        return $home;
    }

    private function version(string $client): array
    {
        if ($client === 'rtorrent') return array('0.9.8-r16', array('kind' => 'static'));
        return $client === 'deluge'
            ? array('2.1.1', array('kind' => 'venv'))
            : array('4.1.3', array('kind' => 'static', 'rpcUnixSocket' => true));
    }

    public function testSuccessfulSwitchOrdersOperationsAndWritesPrivateState(): void
    {
        foreach (array('deluge', 'transmission', 'rtorrent') as $client) {
            $home = $this->home($client);
            list($version, $entry) = $this->version($client);
            $events = array();
            $lockPath = $home.'/.config/pmss/torrentClients/'.$client.'.lock';
            $record = static function ($step) use (&$events, $lockPath): bool {
                $other = fopen($lockPath, 'c');
                $busy = !flock($other, LOCK_EX | LOCK_NB);
                fclose($other);
                $events[] = $step;
                return $busy;
            };
            $ops = array('versions' => array($version => $entry),
                'backup' => static function () use ($record): array {
                    return array('ok' => $record('backup'));
                },
                'stop' => static function () use ($record): bool { return $record('stop'); },
                'repoint' => static function () use ($record): bool { return $record('repoint'); },
                'start' => static function () use ($record): bool { return $record('start'); });
            $result = \pmssConfigureActionApply($home, 'torrentClientSwitch',
                array('client' => $client, 'version' => $version, 'password' => 'secret'), $ops);
            $this->assertSame(200, $result['status']);
            $this->assertSame(array('backup', 'stop', 'repoint', 'start'), $events);
            $stateDir = $home.'/.config/pmss/torrentClients/';
            $this->assertSame($version."\n", file_get_contents($stateDir.$client.'.lastgood'));
            $this->assertSame($version."\n", file_get_contents($stateDir.$client.'.lastRun'));
            $this->assertSame(0600, fileperms($stateDir.$client.'.notice') & 0777);
        }
    }

    public function testFailedStartRestoresPreviousSelection(): void
    {
        foreach (array('deluge', 'transmission', 'rtorrent') as $client) {
            $home = $this->home($client);
            list($version, $entry) = $this->version($client);
            $old = '/home/.pmss/clients/'.$client.'/old';
            $this->pmssCreateSymlinkOrSkip($old, $home.'/bin/'.$client.'/default');
            $events = array();
            $ops = array('versions' => array($version => $entry),
                'resolve' => static function () { return '/fixture/bin'; },
                'backup' => static function (): array { return array('ok' => true); },
                'stop' => static function () use (&$events): bool { $events[] = 'stop'; return true; },
                'repoint' => static function ($path, $name, $pick, $previous = null) use (&$events, $old): bool {
                    $events[] = array('repoint', $pick, $previous);
                    return $previous === null || $previous === $old;
                },
                'start' => static function ($path, $name, $pick) use (&$events): bool {
                    $events[] = array('start', $pick);
                    return $pick === 'old';
                });
            $result = \pmssConfigureTorrentClientOtherSwitch($home,
                array('client' => $client, 'version' => $version), $ops);
            $this->assertSame(503, $result['status']);
            $this->assertSame(array('stop', array('repoint', $version, null), array('start', $version),
                'stop', array('repoint', 'old', $old), array('start', 'old')), $events);
            $this->assertSame("old\n", file_get_contents($home.'/.config/pmss/torrentClients/'.$client.'.lastRun'));
            $this->assertFalse(is_file($home.'/.delugeEnable'), 'a failed switch does not turn Deluge on');
        }
    }

    public function testUnavailableVersionsAndPasswordsDoNoWork(): void
    {
        foreach (array('deluge', 'transmission', 'rtorrent') as $client) {
            $home = $this->home($client);
            list($version, $entry) = $this->version($client);
            $events = array();
            $ops = array('versions' => array($version => $entry),
                'backup' => static function () use (&$events): array { $events[] = 'backup'; return array('ok' => true); });
            $base = array('client' => $client, 'version' => $version);
            $this->assertSame(403, \pmssConfigureActionApply($home, 'torrentClientSwitch', $base, $ops)['status']);
            $this->assertSame(403, \pmssConfigureActionApply($home, 'torrentClientSwitch',
                $base + array('password' => 'wrong'), $ops)['status']);
            foreach (array('../bad', '', 'missing') as $invalid) {
                $request = array('client' => $client, 'version' => $invalid, 'password' => 'secret');
                $this->assertSame(400, \pmssConfigureActionApply($home, 'torrentClientSwitch', $request, $ops)['status']);
            }
            $bad = $entry;
            $bad['kind'] = $client === 'deluge' ? 'static' : 'system';
            $this->assertSame(400, \pmssConfigureTorrentClientOtherSwitch($home, $base,
                array('versions' => array($version => $bad)))['status']);
            $this->assertSame(array(), $events);
        }
    }

    public function testRegularDefaultPathIsRefusedBeforeBackupOrStop(): void
    {
        foreach (array('deluge', 'transmission', 'rtorrent') as $client) {
            $home = $this->home($client);
            list($version, $entry) = $this->version($client);
            $this->pmssWriteFile($home.'/bin/'.$client.'/default', 'keep');
            $events = array();
            $ops = array('versions' => array($version => $entry),
                'backup' => static function () use (&$events): array { $events[] = 'backup'; return array('ok' => true); });
            $result = \pmssConfigureTorrentClientOtherSwitch($home,
                array('client' => $client, 'version' => $version), $ops);
            $this->assertSame(409, $result['status']);
            $this->assertSame(array(), $events);
            $this->assertSame('keep', file_get_contents($home.'/bin/'.$client.'/default'));
        }
    }
}
