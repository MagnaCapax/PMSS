<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWatchdog.php';

/** Port convergence is stopped-only and follows pending-save restoration. */
final class QbittorrentWatchdogPortReconcileTest extends TestCase
{
    public function testThrottleRestartComparesThenRechecksExitBeforeWriting(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/qbittorrentThrottleWatchdog.php', [
            '$differs = $account($user, $home, $throttle, false);',
            'if ($differs !== true) return true;',
            "pmssTorrentClientNativeStop(\$user, 'qbittorrent', 15);",
            '$stop($user);',
            '$running = $runningRead($user);',
            'if ($running) return true;',
            'pmssUserWatchdogApplyManagedConfigWhenStopped($user, $running,',
            '$applied = $account($user, $home, $throttle, true);',
            'if ($applied === null) return true;',
            'return false;',
        ]);
    }

    public function testUserConfigLeavesLiveThrottleDriftForWatchdog(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/userConfig.php', [
            "require_once __DIR__.'/../lib/torrentClients/identity.php';",
            "if (pmssTorrentClientLauncherLivenessPids(\$user['name'], 'qbittorrent') === []) {",
            "pmssQbittorrentApplyUploadThrottle(\$user['name'], \$throttle);",
        ]);
    }

    public function testSlowShutdownDefersTheStoppedWriterAndStartUntilALaterPass(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-slow-stop-').'/alice';
        $this->pmssWriteFile($home.'/.torrentThrottle', "256\n");
        $running = true;
        $events = [];
        $ops = [
            'read' => static function (): int { return 256; },
            'running' => static function () use (&$events, &$running): bool { $events[] = 'running'; return $running; },
            'account' => static function ($user, $home, $cap, $apply) use (&$events, &$running): bool {
                if ($apply && $running) throw new \RuntimeException('Slow shutdown reached the stopped writer');
                $events[] = $apply ? 'write' : 'compare'; return true;
            },
            'stop' => static function () use (&$events): void { $events[] = 'term'; },
            'log' => static function (): void {},
        ];
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog('alice', $home, true, $ops));
        $this->assertSame(['compare', 'term', 'running'], $events);
        $running = false;
        $events = [];
        $this->assertFalse(\pmssQbittorrentThrottleWatchdog('alice', $home, false, $ops));
        $this->assertSame(['running', 'write'], $events);
    }

    public function testWatchdogLoadsAndRunsWebUiPortReconcilerBeforeRestartDecisions(): void
    {
        $events = [];
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('t', '/fixture/t', [
            'running' => static function (): bool { return false; },
            'account' => static function (): bool { return true; },
            'port' => static function () use (&$events): bool { $events[] = 'port'; return false; },
            'auth' => static function () use (&$events): bool { $events[] = 'auth'; return false; },
            'managed' => static function () use (&$events): void { $events[] = 'managed'; },
            'throttle' => static function ($user, $running) use (&$events): bool { $events[] = 'throttle'; return $running; },
        ]));
        $this->assertSame(['port', 'auth', 'managed', 'throttle'], $events);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/checkQbittorrentInstances.php', [
            "require_once __DIR__.'/../lib/user/watchdog.php';",
            "require_once __DIR__.'/../lib/user/torrentPort.php';",
            "require_once __DIR__.'/../lib/lighttpd/dockerClientProxy.php';",
            "if (pmssLighttpdDockerClientPort(\$thisUser, 'qbittorrent') !== null) return ['qbittorrent-nox' => true];",
            "\$home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.\$thisUser;",
            'pmssQbittorrentWatchdogRunning($thisUser, $home)',
        ]);
    }

    public function testStoppedRestoreFailureBlocksWritersAndStart(): void
    {
        $events = [];
        $unreachable = static function (): void { throw new \RuntimeException('Refused restore reached a managed writer'); };
        $this->assertTrue(\pmssQbittorrentWatchdogRunning('t', '/fixture/t', [
            'running' => static function (): bool { return false; },
            'account' => static function () use (&$events): bool { $events[] = 'restore'; return false; },
            'port' => $unreachable, 'auth' => $unreachable, 'managed' => $unreachable,
        ]));
        $this->assertSame(['restore'], $events);
    }

    public function testUnrepairedMarkerStillGetsEveryStoppedRepairAndBlocksStart(): void
    {
        $events = [];
        $this->assertTrue(\pmssQbittorrentWatchdogRunning('t', '/fixture/t', [
            'running' => static function (): bool { return false; },
            'account' => static function ($user, $home, $observe = false, $release = false) use (&$events): bool {
                $events[] = $release ? 'release' : 'restore'; return !$release;
            },
            'port' => static function () use (&$events): bool { $events[] = 'port'; return true; },
            'auth' => static function () use (&$events): bool { $events[] = 'auth'; return false; },
            'managed' => static function () use (&$events): void { $events[] = 'managed'; },
            'throttle' => static function (): void { throw new \RuntimeException('Refused account reached restart decisions'); },
        ]));
        $this->assertSame(['restore', 'port', 'auth', 'managed', 'release'], $events);
        // The cron file is top-level with no injectable entry point; retain only its delegation check.
        $this->pmssAssertRepoFileContainsString('scripts/cron/checkQbittorrentInstances.php', 'pmssQbittorrentWatchdogRunning($thisUser, $home)');
    }
}
