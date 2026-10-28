<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** Real fixture links and locks, with daemon operations replaced by recording closures. */
final class TorrentClientsSwitchRecoveryTest extends TestCase
{
    private function apply($home, $client, $version, $ops): array
    {
        $request = ['client' => $client, 'version' => $version];
        return $client === 'qbittorrent' ? \pmssConfigureTorrentClientSwitch($home, $request, $ops)
            : \pmssConfigureTorrentClientOtherSwitch($home, $request, $ops);
    }

    public function testInvalidSelectionsOnlyRecoverToOfferedSystemAndRestoreOriginalLinkOnFailure(): void
    {
        foreach (['qbittorrent', 'deluge', 'rtorrent'] as $client) foreach ([true, false] as $starts) {
            foreach (['/home/.pmss/clients/'.$client.'/withdrawn', 'missing-custom', 'system'] as $target) {
                $home = $this->pmssMakeTempDir('pmss-invalid-recovery-');
                $this->pmssEnsureDir($home.'/bin/'.$client);
                $link = $home.'/bin/'.$client.'/default';
                $this->pmssCreateSymlinkOrSkip($target, $link);
                $events = [];
                $ops = ['versions' => ['system' => ['kind' => 'system'], '4.2.0' => ['kind' => $client === 'deluge' ? 'venv' : 'static']],
                    'repoint' => static function ($home, ...$args) use ($client, &$events): bool {
                        if ($client !== 'qbittorrent') array_shift($args);
                        $events[] = 'repoint-'.$args[0];
                        return $client === 'qbittorrent' ? \pmssConfigureTorrentClientRepoint($home, ...$args)
                            : \pmssConfigureTorrentClientOtherRepoint($home, $client, ...$args);
                    },
                    'start' => static function ($home, ...$args) use (&$events, $starts): bool {
                        $events[] = 'start-'.end($args); return $starts;
                    },
                ]
                    + self::pmssMakeCallbacks(['resolve' => null, 'runningVersion' => 'system', 'restore' => true])
                    + self::pmssMakeCallbacks(['backup' => ['ok' => true], 'stop' => true], $events);
                $this->assertSame(409, $this->apply($home, $client, '4.2.0', $ops)['status']);
                $this->assertSame([], $events);
                $result = $this->apply($home, $client, 'system', $ops);
                $this->assertSame($starts, $result['ok']);
                $this->assertSame($starts ? 200 : 503, $result['status']);
                $expected = ['backup', 'stop', 'repoint-system', 'start-system'];
                if ($starts) $this->assertFalse(is_link($link));
                else {
                    $expected = array_merge($expected, ['stop', 'repoint-invalid', 'start-invalid']);
                    $this->assertSame($target, readlink($link));
                    $this->assertStringContainsString('manual recovery', $result['message']);
                }
                $this->assertSame($expected, $events);
            }
        }
    }

    public function testBackupFailureReturnsItsReasonWithoutStopOrRestoreAndReleasesLock(): void
    {
        foreach (['qbittorrent', 'rtorrent', 'deluge', 'transmission'] as $client) {
            foreach ([['ok' => false, 'message' => 'Not enough free space.'], false, null] as $snapshot) {
                $home = $this->pmssMakeTempDir('pmss-backup-failed-');
                $this->pmssEnsureDir($home.'/bin/'.$client);
                $target = '/home/.pmss/clients/'.$client.'/4.1.3';
                $this->pmssCreateSymlinkOrSkip($target, $home.'/bin/'.$client.'/default');
                $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
                foreach (['lastRun', 'lastgood'] as $suffix) $this->pmssWriteFile($dir.'/'.$client.'.'.$suffix, "4.1.3\n");
                $events = [];
                $unexpected = static function (): void { throw new \RuntimeException('Backup failure changed the client'); };
                $ops = ['versions' => ['4.2.0' => ['kind' => $client === 'deluge' ? 'venv' : 'static', 'rpcUnixSocket' => true]],
                    'stop' => $unexpected, 'repoint' => $unexpected, 'start' => $unexpected,
                ] + self::pmssMakeCallbacks(['resolve' => '/fixture/binary'])
                    + self::pmssMakeCallbacks(['backup' => $snapshot], $events);
                $result = $this->apply($home, $client, '4.2.0', $ops);
                $message = 'Could not back up the current configuration: '
                    .(is_array($snapshot) ? 'Not enough free space' : 'Backup failed').'. Nothing was changed.';
                $this->assertSame(['ok' => false, 'status' => 503, 'message' => $message], $result);
                $this->assertSame($message."\n", file_get_contents($dir.'/'.$client.'.notice'));
                $this->assertSame(0600, fileperms($dir.'/'.$client.'.notice') & 0777);
                $this->assertSame($target, readlink($home.'/bin/'.$client.'/default'));
                foreach (['lastRun', 'lastgood'] as $suffix) $this->assertSame("4.1.3\n", file_get_contents($dir.'/'.$client.'.'.$suffix));
                $this->assertSame(['backup'], $events);
                $lock = fopen($dir.'/'.$client.'.lock', 'c');
                $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
                fclose($lock);
            }
        }
    }

    public function testInvalidTransmissionStillCannotSelectSystem(): void
    {
        $home = $this->pmssMakeTempDir('pmss-invalid-transmission-');
        $this->pmssEnsureDir($home.'/bin/transmission');
        $this->pmssCreateSymlinkOrSkip('/home/.pmss/clients/transmission/missing', $home.'/bin/transmission/default');
        $ops = ['versions' => ['system' => ['kind' => 'system', 'rpcUnixSocket' => true]]] + self::pmssMakeCallbacks(['resolve' => null]);
        $this->assertSame(400, $this->apply($home, 'transmission', 'system', $ops)['status']);
        $this->assertFalse(is_dir($home.'/.config'));
    }
}
