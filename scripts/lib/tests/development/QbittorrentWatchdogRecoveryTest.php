<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once __DIR__.'/../../user/qbittorrentWatchdog.php';

/** Observe once per cron pass, and allow managed stopped repairs behind a refusal. */
final class QbittorrentWatchdogRecoveryTest extends TemporaryHomeRootTestCase
{
    public function testStoppedRefusalStillRunsPortAndAuthRepairBeforeRelease(): void
    {
        $events = [];
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('t', '/fixture/t', [
            'account' => static function ($user, $home, $observe = false, $release = false) use (&$events): bool {
                $events[] = $release ? 'release' : 'restore'; return true;
            },
            'throttle' => static function ($user, $running): bool { return $running; },
        ]
            + self::pmssMakeCallbacks(['running' => false])
            + self::pmssMakeCallbacks(['port' => true, 'auth' => false, 'managed' => null], $events)));
        $this->assertSame(['restore', 'port', 'auth', 'managed', 'release'], $events);
    }

    public function testRunningCustomerPortDoesNotReachStoppedPortReconcile(): void
    {
        $events = [];
        $this->assertTrue(\pmssQbittorrentWatchdogRunning('t', '/fixture/t', [
            'account' => static function ($user, $home, $observe) use (&$events): bool {
                $events[] = $observe ? 'observe' : 'restore'; return true;
            },
            'port' => static function (): void { throw new \RuntimeException('Running customer port was reconciled'); },
            'managed' => static function ($user, $running) use (&$events): void { $events[] = $running ? 'running' : 'stopped'; },
            'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['running' => true, 'auth' => false])));
        $this->assertSame(['observe', 'running'], $events);
    }

    public function testDefaultFallbackStopsAndLogsOnceThenSafeConfigReleasesTheMarker(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-fallback-cycle-');
        $config = "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\nPassword_PBKDF2=@ByteArray(c2FsdA==:aGFzaA==)\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Meta]\nMigrationVersion=12\n[WebUI]\nPort=23456\n");
        $this->pmssWriteFile($home.'/.qbittorrentPort', '23456');
        $stops = 0; $notices = 0; $live = true;
        $ops = ['attempts' => 1, 'build' => '5.3.0',
            'pids' => static function () use (&$live): array { return $live ? [101] : []; },
            'stop' => static function () use (&$stops, &$live): bool { $stops++; $live = false; return true; },
            'notice' => static function ($home, $text) use (&$notices): void { $notices++; \pmssQbittorrentFrontendNotice($home, $text); },
            'pause' => static function (): void { throw new \RuntimeException('Per-cycle observation slept'); },
        ] + self::pmssMakeCallbacks(['listeners' => [['address' => '00000000', 'port' => 8080]]]);
        $watchdog = ['running' => static function () use (&$live): bool { return $live; },
            'account' => static function ($user, $home, $observe = false, $release = false) use ($ops): bool {
                if ($observe) return \pmssQbittorrentFrontendSocketWait($home, $ops);
                if (!$release) return true;
                return \pmssQbittorrentFrontendRefusalReady($home, [
                    'build' => '5.3.0',
                ] + self::pmssMakeCallbacks(['accountPids' => []]));
            },
            'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['port' => false, 'auth' => false, 'managed' => null]);
        for ($cycle = 0; $cycle < 2; $cycle++)
            $this->assertTrue(\pmssQbittorrentWatchdogRunning('t', $home, $watchdog));
        $this->assertSame(1, $stops);
        $this->assertSame(1, $notices);
        $this->pmssWriteFile($path, $config);
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('t', $home, $watchdog));
        $this->assertFalse(file_exists($home.'/.config/pmss/torrentClients/qbittorrent.refused'));
    }
}
