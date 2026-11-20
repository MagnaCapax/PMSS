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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/checkRtorrent.php', [
            "\$pmssCheckRtorrentLock = pmssCronLockAcquire('checkRtorrent'",
            'pmssCheckRtorrentLog($message, false, $debug);',
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

    /** Run the real restart script with the executor lock held and the run stamp set to $stamp (null: no file). */
    private function runRestartWithStamp(?string $stamp): array
    {
        $home = $this->pmssMakeTempDir('pmss-restart-stamp-');
        $this->pmssWriteFile($home.'/.scriptsInc.php', '<?php');
        $this->pmssWriteFile($home.'/www/.rtorrentRestart', '');
        if ($stamp !== null) {
            $this->pmssWriteFile($home.'/.rtorrentExecuteRun', $stamp);
        }
        $source = $this->pmssReadRepoFile('etc/skel/.rtorrentRestart.php');
        $source = preg_replace('/\A#![^\n]*\n<\?php\n/', '', $source);
        // Shims keep the run inert: no sleeping, no screen session. Holding the lock keeps the idle short-circuit
        // false, so the age arithmetic on the run stamp is the only thing that can decide the launch.
        $shims = <<<'PHP'
<?php
namespace PMSS\RestartFixture;
$launches = 0;
$error = null;
function writeLog($message) { }
function sleep($seconds) { return 0; }
function system($command) { $GLOBALS['launches']++; return ''; }
$heldLock = fopen('.rtorrentExecute.lock', 'c');
flock($heldLock, LOCK_EX | LOCK_NB);
register_shutdown_function(static function () {
    echo json_encode(['launches' => $GLOBALS['launches'], 'error' => $GLOBALS['error']]);
});
try {
PHP;
        $script = $this->pmssWriteFile($home.'/.rtorrentRestart.php', $shims."\n".$source
            ."\n} catch (\\Throwable \$e) {\n\$GLOBALS['error'] = get_class(\$e).': '.\$e->getMessage();\n}\n");
        return $this->pmssRunInlinePhpJson('chdir('.var_export($home, true).'); require '.var_export($script, true).';');
    }

    public function testRunStampThatIsEmptyOrNotNumericIsTreatedAsNeverRun(): void
    {
        // On PHP 8 "" == 0 is false and int - string throws, so an empty stamp (the executor rewrites it with
        // file_put_contents) killed the script before it started the executor. Each case must launch exactly once.
        foreach (['', ' ', "\n", 'abc', '12abc'] as $stamp) {
            $this->assertSame(['launches' => 1, 'error' => null], $this->runRestartWithStamp($stamp), var_export($stamp, true));
        }
        $this->assertSame(['launches' => 1, 'error' => null], $this->runRestartWithStamp(null), 'missing stamp');
        // Version-independent guard: the stamp is cast to int, on any PHP (the behaviour above only differs on PHP 8).
        $this->pmssAssertRepoFileContainsString('etc/skel/.rtorrentRestart.php',
            "(int) trim((string) file_get_contents('.rtorrentExecuteRun'))");
    }

    public function testNumericRunStampStillGovernsTheSixtySecondRule(): void
    {
        $this->assertSame(['launches' => 0, 'error' => null], $this->runRestartWithStamp((string) (time() + 3600)), 'fresh');
        $this->assertSame(['launches' => 0, 'error' => null], $this->runRestartWithStamp((time() + 3600)."\n"), 'fresh with newline');
        $this->assertSame(['launches' => 1, 'error' => null], $this->runRestartWithStamp((string) (time() - 3600)), 'stale');
        $this->assertSame(['launches' => 1, 'error' => null], $this->runRestartWithStamp((time() - 3600)."\n"), 'stale with newline');
    }

    public function testRestartGraceKeepsSharedHelperLaunchAndMarkers(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/checkRtorrent.php', [
            'pmssCheckRtorrentHandleMissingProcess(',
            'pmssCheckRtorrentHandleAliveProcess(',
            'PMSS_RTORRENT_MISSING_GRACE',
            'PMSS_RTORRENT_START_FAILURE_SESSION_RESET',
            'PMSS_RTORRENT_START_FAILURE_ESCALATE',
            'PMSS_RTORRENT_UNRESPONSIVE_GRACE',
            'PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES',
        ], ["@passthru('/scripts/startRtorrent "]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/process.php', [
            "require_once __DIR__.'/watchdogState.php';",
            "require_once __DIR__.'/processLifecycle.php';",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/processLifecycle.php', [
            'function rtorrentProcessStart(',
            'rtorrentProcessWriteStateFile(rtorrentProcessRestartMarkerPath($user), $now);',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/rtorrent/watchdog.php', "require_once __DIR__.'/watchdogProcessFlow.php';");
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/watchdogProcessFlow.php', [
            "require_once __DIR__.'/watchdogEscalation.php';",
            "require_once __DIR__.'/watchdogMissing.php';",
            "require_once __DIR__.'/watchdogScgi.php';",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/watchdogState.php', [
            "require_once __DIR__.'/watchdogEscalation.php';",
            "require_once __DIR__.'/watchdogScgi.php';",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/watchdogMissing.php', [
            'function pmssCheckRtorrentHandleMissingProcess(',
            "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
            'persistent start failure recovery: reset session directory',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/rtorrent/watchdogEscalation.php',
            'persistent start failure escalated; leaving retry disabled for external monitoring');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/rtorrent/watchdogScgi.php', [
            'function pmssCheckRtorrentHandleAliveProcess(',
            'rtorrentProcessUnresponsiveGraceState($restartMarker, $unresponsiveGrace)',
            "time() - \$graceState['restartAge']",
            '$effectiveGrace = $graceState[\'grace\'];',
            'rtorrentProcessScgiUnresponsiveDecision(',
            'function rtorrentProcessUnresponsiveGraceState(',
            '$restartAge < 7200',
            '$restartAge < 14400',
            'max($baseGrace, $restartAge < 7200 ? 600 : 1200)',
        ]);
    }
}
