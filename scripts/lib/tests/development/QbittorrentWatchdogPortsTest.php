<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWatchdog.php';
require_once __DIR__.'/../../user/qbittorrentWatchdogPorts.php';

/** Reservation requests and watchdog convergence use isolated files and recording operations. */
final class QbittorrentWatchdogPortsTest extends TestCase
{
    private function home(): string
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-ports-').'/fixtur01';
        $this->pmssWriteFile($home.'/.qbittorrentPort', '24000');
        return $home;
    }

    public function testPortManagerRequestPreservesReservationsForRejectedPreferences(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-qbt-reservations-');
        $this->pmssWriteFile($directory.'/qbittorrent-fixtur01', '24000');
        $this->pmssWriteFile($directory.'/lighttpd-fixtur02', '25000');
        $this->pmssWriteFile($directory.'/legacy/scgi/26000', '');
        $script = 'namespace QbittorrentPortRequestFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/portManager.php'), true).';';
        // No actual socket bind: exercise the allocator's request policy with a deterministic probe.
        $script .= 'function pmssPortManagerPortIsAvailable(int $port): bool { return $port !== 27000; }';
        $source = $this->pmssReadRepoFile('scripts/lib/portManager/selection.php');
        $source = substr($source, strpos($source, 'function pmssPortManagerRequestServicePort('));
        $script .= $source;
        $script .= <<<'PHP'
$results = [];
foreach ([null, 80, 1999, 38001, 45678, 65536, 25000, 26000, 27000, 24000, 28000] as $preferred) {
    $port = pmssPortManagerRequestServicePort('fixtur01', 'qbittorrent', $preferred);
    $results[] = [$port, \file_get_contents(\getenv('PMSS_PORT_MANAGER_DIR').'/qbittorrent-fixtur01')];
}
$results[] = pmssPortManagerRequestServicePort('../fixtur01', 'qbittorrent', 28000);
$results[] = pmssPortManagerRequestServicePort('fixtur01', '../service', 28000);
echo \json_encode($results);
PHP;
        $result = $this->pmssRunInlinePhpJson($script, [
            'PMSS_PORT_MANAGER_DIR' => $directory, 'PMSS_PORT_MANAGER_LEGACY_DIR' => $directory.'/legacy',
        ]);
        $this->assertSame(array_merge(array_fill(0, 10, [24000, '24000']), [[28000, '28000'], null, null]), $result);
    }

    public function testResolutionPublishesOnlyTheAssignedPortAndDefersOnFailure(): void
    {
        $home = $this->home();
        foreach (['25000', '45678', 'invalid', ''] as $preference) {
            $this->pmssWriteFile($home.'/.qbittorrentPort', $preference);
            $events = [];
            $ops = ['assign' => static function ($user, $service, $preferred) use (&$events): int {
                $events[] = [$user, $service, $preferred]; return 24000;
            }, 'write' => static function ($path, $port): bool { return file_put_contents($path, (string) $port) !== false; }];
            $this->assertSame(24000, \pmssQbittorrentWatchdogPortResolve('fixtur01', $home, $ops));
            $this->assertSame('24000', file_get_contents($home.'/.qbittorrentPort'));
            $this->assertSame([['fixtur01', 'qbittorrent', ctype_digit($preference) ? (int) $preference : null]], $events);
        }
        $this->pmssWriteFile($home.'/.qbittorrentPort', '25000');
        foreach ([['assign' => null, 'write' => true], ['assign' => 24000, 'write' => false]] as $results) {
            $this->assertSame(null, \pmssQbittorrentWatchdogPortResolve('fixtur01', $home, self::pmssMakeCallbacks($results)));
            $this->assertSame('25000', file_get_contents($home.'/.qbittorrentPort'));
        }
    }

    public function testUnsafePortCopyCannotReachAllocatorOrWriter(): void
    {
        $home = $this->home();
        $target = $this->pmssWriteFile($home.'/unmanaged', '25000');
        unlink($home.'/.qbittorrentPort');
        symlink($target, $home.'/.qbittorrentPort');
        $unreachable = static function (): void { throw new \RuntimeException('Unsafe path reached allocation'); };
        $this->assertSame(null, \pmssQbittorrentWatchdogPortResolve('fixtur01', $home, ['assign' => $unreachable]));
        $this->assertSame('25000', file_get_contents($target));
    }

    public function testFailedConvergenceNeverReachesLaunchDecisions(): void
    {
        foreach ([['portResolve' => null], ['port' => false], ['proxy' => false]] as $failure) {
            $ops = self::pmssMakeCallbacks($failure + ['portResolve' => 24000, 'running' => false,
                'account' => true, 'port' => true, 'proxy' => true, 'auth' => false, 'managed' => null]);
            $ops['throttle'] = static function (): void { throw new \RuntimeException('Failed convergence reached launch'); };
            $this->assertTrue(\pmssQbittorrentWatchdogRunning('fixtur01', '/fixture/fixtur01', $ops));
        }
    }

    public function testProxySkipsIdenticalBytesAndRetainsWriterFailure(): void
    {
        $home = $this->home();
        $path = $this->pmssWriteFile($home.'/.lighttpd/custom.d/pmss-qbittorrent.conf', \pmssLighttpdManagedProxyFragment('qbittorrent', 'fixtur01', 24000));
        touch($path, 1000000000);
        $events = [];
        $ops = ['write' => static function ($app, $user, $port, $path) use (&$events): bool { $events[] = $port; return false; }];
        $this->assertTrue(\pmssQbittorrentWatchdogProxyEnsure('fixtur01', $home, 24000, $ops));
        $this->assertSame([], $events);
        $this->assertSame(1000000000, filemtime($path));
        $this->assertFalse(\pmssQbittorrentWatchdogProxyEnsure('fixtur01', $home, 25000, $ops));
        $this->assertSame([25000], $events);
    }

    public function testRunningPortDriftWaitsForExitThenConvergesBeforeLaunch(): void
    {
        $home = $this->home();
        $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=25000\n");
        $live = true; $exit = false; $events = [];
        $ops = ['running' => static function () use (&$live): bool { return $live; },
            'portRepairStop' => static function () use (&$live, &$exit, &$events): void { $events[] = 'term'; $live = !$exit; },
            'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['portResolve' => 24000, 'portRepairNeeded' => false, 'auth' => false, 'managed' => null])
            + self::pmssMakeCallbacks(['account' => true, 'port' => true, 'proxy' => true], $events);
        $this->assertTrue(\pmssQbittorrentWatchdogRunning('fixtur01', $home, $ops));
        $this->assertSame(['term'], $events);
        $events = []; $exit = true;
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('fixtur01', $home, $ops));
        $this->assertSame(['term', 'account', 'port', 'proxy', 'account'], $events);
    }

    public function testAssignedPortRemainsPinnedAcrossHomeCopyEdits(): void
    {
        $home = $this->home();
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=25000\n");
        $this->pmssWriteFile($home.'/.qbittorrentPort', '45678');
        $this->assertFalse(\pmssQbittorrentPortEnsure('fixtur01', $home, 80));
        $this->assertTrue(\pmssQbittorrentPortEnsure('fixtur01', $home, 24000));
        $this->assertSame('24000', \pmssQbittorrentWebUiValue(file_get_contents($path), 'Port', \pmssQbittorrentBuildVersion($home)));
    }

    public function testStoppedCycleRestoresAllCopiesFromReservation(): void
    {
        $home = $this->home();
        $directory = $this->pmssMakeTempDir('pmss-qbt-cycle-');
        $reservation = $this->pmssWriteFile($directory.'/qbittorrent-fixtur01', '24000');
        $this->pmssTrackEnvOverrides(['PMSS_PORT_MANAGER_DIR' => $directory]);
        $this->pmssWriteFile($home.'/.qbittorrentPort', '45678');
        $config = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=45678\n");
        $proxy = $this->pmssWriteFile($home.'/.lighttpd/custom.d/pmss-qbittorrent.conf', 'stale');
        $ops = ['portResolve' => static function ($user, $home): ?int {
            return \pmssQbittorrentWatchdogPortResolve($user, $home, [
                'write' => static function ($path, $port): bool { return file_put_contents($path, (string) $port) !== false; },
            ]);
        }, 'proxy' => static function ($user, $home, $assigned): bool {
            return \pmssQbittorrentWatchdogProxyEnsure($user, $home, $assigned, [
                'write' => static function ($app, $user, $port, $path): bool {
                    return file_put_contents($path, \pmssLighttpdManagedProxyFragment($app, $user, $port)) !== false;
                },
            ]);
        }, 'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['running' => false, 'account' => true, 'auth' => false, 'managed' => null]);
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('fixtur01', $home, $ops));
        $this->assertSame('24000', file_get_contents($reservation));
        $this->assertSame('24000', file_get_contents($home.'/.qbittorrentPort'));
        $this->assertSame('24000', \pmssQbittorrentWebUiValue(file_get_contents($config), 'Port', \pmssQbittorrentBuildVersion($home)));
        $this->assertSame(\pmssLighttpdManagedProxyFragment('qbittorrent', 'fixtur01', 24000), file_get_contents($proxy));
    }
}
