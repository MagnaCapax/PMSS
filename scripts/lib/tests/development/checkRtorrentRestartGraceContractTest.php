<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentRestartGraceContractTest extends TestCase
{
    public function testSingleInstanceLockHandleIsRetained(): void
    {
        // The lock-acquire result MUST be assigned to a variable that lives for
        // the whole run. A bare `pmssLockFileAcquire(...) === false` discards the
        // returned stream handle, PHP closes the fd at statement end, and the
        // flock releases immediately — making the single-instance guard a no-op
        // (regression fixed 2026-06-10; the */2min watchdog has no external flock
        // wrapper, so this in-script lock is the only concurrency guard).
        $this->pmssAssertRepoFileContract('scripts/cron/checkRtorrent.php', [
            'required' => [
                "\$pmssCheckRtorrentLock = pmssCronLockAcquire('checkRtorrent'",
                'pmssCheckRtorrentLog($message, false, $debug);',
            ],
        ]);
    }

    public function testTamperDetectionRunsBeforeProcessStateBranches(): void
    {
        // Every process-state branch below the tamper check continues out of
        // the loop iteration; the check must therefore run BEFORE them or it
        // is unreachable and /root/changedConfigs silently never reports
        // (regression caught 2026-06-10 during the watchdog refactor).
        $this->pmssAssertRepoFileContract('scripts/cron/checkRtorrent.php', [
            'required' => [
                "pmssCheckRtorrentPublishChangedConfigReport(\$changedConfig, '/root/changedConfigs', \$debug);",
            ],
            'ordered' => [[
                'needles' => [
                    "} elseif (function_exists('posix_getpwuid')) {",
                    "\$changedConfig[] = \$user.' -> '.\$owner['name'];",
                    '$executor = rtorrentProcessExecutorPids($user);',
                ],
                'missingPrefix' => 'checkRtorrent tamper detection contract missing: ',
            ]],
        ]);
    }

    public function testRestartGraceKeepsSharedHelperLaunchAndMarkers(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkRtorrent.php' => [
                'required' => [
                    'pmssCheckRtorrentHandleMissingProcess(',
                    'pmssCheckRtorrentHandleAliveProcess(',
                    'PMSS_RTORRENT_MISSING_GRACE',
                    'PMSS_RTORRENT_START_FAILURE_SESSION_RESET',
                    'PMSS_RTORRENT_START_FAILURE_ESCALATE',
                    'PMSS_RTORRENT_UNRESPONSIVE_GRACE',
                    'PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES',
                ],
                'forbidden' => ["@passthru('/scripts/startRtorrent "],
            ],
            'scripts/lib/rtorrent/process.php' => ['required' => [
                "require_once __DIR__.'/watchdogState.php';",
                "require_once __DIR__.'/processLifecycle.php';",
            ]],
            'scripts/lib/rtorrent/processLifecycle.php' => ['required' => [
                'function rtorrentProcessStart(',
                'rtorrentProcessWriteStateFile(rtorrentProcessRestartMarkerPath($user), $now);',
            ]],
            'scripts/lib/rtorrent/watchdog.php' => ['required' => [
                "require_once __DIR__.'/watchdogProcessFlow.php';",
            ]],
            'scripts/lib/rtorrent/watchdogProcessFlow.php' => ['required' => [
                "require_once __DIR__.'/watchdogEscalation.php';",
                "require_once __DIR__.'/watchdogMissing.php';",
                "require_once __DIR__.'/watchdogScgi.php';",
            ]],
            'scripts/lib/rtorrent/watchdogState.php' => ['required' => [
                "require_once __DIR__.'/watchdogEscalation.php';",
                "require_once __DIR__.'/watchdogScgi.php';",
            ]],
            'scripts/lib/rtorrent/watchdogMissing.php' => ['required' => [
                'function pmssCheckRtorrentHandleMissingProcess(',
                "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
                'persistent start failure recovery: reset session directory',
            ]],
            'scripts/lib/rtorrent/watchdogEscalation.php' => ['required' => [
                'persistent start failure escalated; leaving retry disabled for external monitoring',
            ]],
            'scripts/lib/rtorrent/watchdogScgi.php' => ['required' => [
                'function pmssCheckRtorrentHandleAliveProcess(',
                'rtorrentProcessUnresponsiveGraceState($restartMarker, $unresponsiveGrace)',
                "time() - \$graceState['restartAge']",
                '$effectiveGrace = $graceState[\'grace\'];',
                'rtorrentProcessScgiUnresponsiveDecision(',
                'function rtorrentProcessUnresponsiveGraceState(',
                '$restartAge < 7200',
                '$restartAge < 14400',
                'max($baseGrace, $restartAge < 7200 ? 600 : 1200)',
            ]],
        ]);
    }
}
