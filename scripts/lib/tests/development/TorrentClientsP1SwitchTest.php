<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';

/** Exercise the customer action with bounded account fixtures and injected I/O. */
final class TorrentClientsP1SwitchTest extends TestCase
{
    private function home(): string
    {
        $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-p1-switch-').'/t');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $hash = crypt('secret', '$6$rounds=5000$pmssswitch$');
        $this->pmssWriteFile($home.'/.lighttpd/.htpasswd', 't:'.$hash."\n");
        return $home;
    }

    public function testProjectionAcceptsOnlyBoundedQbittorrentEntries(): void
    {
        $root = $this->pmssMakeTempDir('pmss-p1-projection-');
        $path = $root.'/projection.json';
        $this->pmssWriteFile($path, json_encode(array('versions' => array(
            array('client' => 'qbittorrent', 'version' => '5.1.0', 'kind' => 'static'),
            array('client' => 'rtorrent', 'version' => '0.9.8', 'kind' => 'static'),
            array('client' => 'qbittorrent', 'version' => '../bad', 'kind' => 'static'),
        ))));
        $this->assertSame(array('5.1.0'), array_keys(\pmssConfigureTorrentClientVersionsRead($path)));
        $link = $root.'/linked.json';
        $this->pmssCreateSymlinkOrSkip($path, $link);
        $this->assertSame(array(), \pmssConfigureTorrentClientVersionsRead($link));
    }

    public function testPasswordAndVersionValidationBeforeCommands(): void
    {
        $home = $this->home();
        $events = array();
        $ops = array('versions' => array('5.1.0' => array('kind' => 'static')),
            'backup' => static function () use (&$events): array { $events[] = 'backup'; return array('ok' => true); });
        $request = array('client' => 'qbittorrent', 'version' => '5.1.0');
        $this->assertSame(403, \pmssConfigureActionApply($home, 'torrentClientSwitch', $request, $ops)['status']);
        $this->assertSame(403, \pmssConfigureActionApply($home, 'torrentClientSwitch', $request + array('password' => 'wrong'), $ops)['status']);
        $this->assertSame(400, \pmssConfigureActionApply($home, 'torrentClientSwitch',
            array('client' => 'qbittorrent', 'version' => '../bad', 'password' => 'secret'), $ops)['status']);
        $this->assertSame(array(), $events);
    }

    public function testSnapshotStopRepointStartShareOneLock(): void
    {
        $home = $this->home();
        $events = array();
        $lockPath = $home.'/.config/pmss/torrentClients/qbittorrent.lock';
        $locked = static function () use ($lockPath): bool {
            $other = fopen($lockPath, 'c');
            $busy = !flock($other, LOCK_EX | LOCK_NB);
            fclose($other);
            return $busy;
        };
        $ops = array(
            'versions' => array('5.1.0' => array('kind' => 'static')),
            'backup' => static function () use (&$events, $locked): array {
                $events[] = 'backup'; return array('ok' => $locked());
            },
            'stop' => static function () use (&$events, $locked): bool {
                $events[] = 'stop'; return $locked();
            },
            'repoint' => static function () use (&$events, $locked): bool {
                $events[] = 'repoint'; return $locked();
            },
            'start' => static function () use (&$events, $locked): bool {
                $events[] = 'start'; return $locked();
            },
        );
        $result = \pmssConfigureActionApply($home, 'torrentClientSwitch',
            array('client' => 'qbittorrent', 'version' => '5.1.0', 'password' => 'secret'), $ops);
        $this->assertSame(200, $result['status']);
        $this->assertSame(array('backup', 'stop', 'repoint', 'start'), $events);
        $this->assertSame("5.1.0\n", file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.lastRun'));
        $this->assertSame("5.1.0\n", file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.lastgood'));
    }

    public function testFailedStartRestoresSystemSelection(): void
    {
        $home = $this->home();
        $events = array();
        $ops = array(
            'versions' => array('5.1.0' => array('kind' => 'static')),
            'backup' => static function (): array { return array('ok' => true); },
            'stop' => static function () use (&$events): bool { $events[] = 'stop'; return true; },
            'repoint' => static function ($path, $version) use (&$events): bool {
                $events[] = 'repoint-'.$version; return true;
            },
            'start' => static function ($path, $version) use (&$events): bool {
                $events[] = 'start-'.$version; return $version === 'system';
            },
        );
        $result = \pmssConfigureTorrentClientSwitch($home,
            array('client' => 'qbittorrent', 'version' => '5.1.0'), $ops);
        $this->assertFalse($result['ok']);
        $this->assertSame(array('stop', 'repoint-5.1.0', 'start-5.1.0', 'stop',
            'repoint-system', 'start-system'), $events);
    }

    public function testFailedRollbackReportsManualRecovery(): void
    {
        $home = $this->home();
        $stops = 0;
        $ops = array(
            'versions' => array('5.1.0' => array('kind' => 'static')),
            'backup' => static function (): array { return array('ok' => true); },
            'stop' => static function () use (&$stops): bool { $stops++; return $stops === 1; },
            'repoint' => static function (): bool { return true; },
            'start' => static function (): bool { return false; },
        );
        $result = \pmssConfigureTorrentClientSwitch($home,
            array('client' => 'qbittorrent', 'version' => '5.1.0'), $ops);
        $this->assertSame(503, $result['status']);
        $this->assertSame(2, $stops);
        $this->assertSame('The switch failed; manual recovery is needed.', $result['message']);
    }
}
