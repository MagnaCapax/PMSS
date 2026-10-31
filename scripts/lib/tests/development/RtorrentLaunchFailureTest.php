<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the launcher entry point with isolated account, process and marker I/O. */
final class RtorrentLaunchFailureTest extends TestCase
{
    /** Run the real entry point in a child; shutdown captures even its early exits. */
    private function observe(array $case): array
    {
        $source = $this->pmssReadRepoFile('scripts/startRtorrent');
        $source = substr($source, strpos($source, '<?php') + 5);
        // Replace dependency loading with namespace-local stubs, retaining the actual control flow.
        $source = preg_replace('/^\h*require_once [^\n]+;\n/m', '', $source);
        $script = <<<'PHP'
namespace RtorrentLaunchFixture;
// Account and filesystem operations never leave this in-memory fixture.
function logmsg($message) { $GLOBALS['result']['messages'][] = $message; }
function pmssUsernameNormalizeIfValid($user) { return $user; }
function pmssResolvePathFromEnv(...$args) { return '/home'; }
function is_file($path) { return false; }
function is_dir($path) { return true; }
function file_exists($path) {
    if (substr($path, -20) === '.rtorrentExecute.php') return $GLOBALS['case']['executor'] ?? true;
    return substr($path, -12) === '.rtorrent.rc';
}
function pmssRuntimeLockPath($path) { return $path; }
function pmssLockFileAcquire(...$args) { return true; }
// Initial probes see no process; only a launch enables the supplied PID samples.
function pmssUserWatchdogProcessPids(...$args) {
    return $GLOBALS['result']['launches'] ? (array_shift($GLOBALS['case']['polls']) ?? []) : [];
}
function rtorrentProcessExecutorPids(...$args) { return ['all' => []]; }
function pmssUserWatchdogBackoffActive(...$args) { return false; }
function pmssBuildUserServiceShellCommand($user, $command, $login) { return $command; }
function pmssUserWatchdogCommandRun(...$args) {
    $GLOBALS['result']['launches']++;
    return $GLOBALS['case']['command'] + ['rc' => 0, 'timed_out' => false, 'stdout' => '', 'stderr' => ''];
}
function pmssUserWatchdogTimeoutSeconds($seconds) { return $seconds; }
function pmssUserWatchdogBackoffRecord(...$args) {}
function pmssAccountPathRun(...$args) { return $GLOBALS['case']['cleanup'] ?? true; }
function exec($command, &$lines, &$rc) { $lines = []; $rc = 0; }
function pmssRuntimeDir() { return '/run/pmss'; }
// Exercise the launcher's observation callback without wall-clock waits.
function rtorrentProcessWaitForStablePids($provider, ...$args) {
    foreach ($GLOBALS['case']['polls'] as $unused) $provider();
    return $GLOBALS['case']['stable'] ?? false;
}
function rtorrentProcessClearStaleState($path) {
    if (substr($path, -6) === '.count') $GLOBALS['result']['count'] = 0;
}
function rtorrentProcessCheckFailureCountState($path, $limit) {
    $count = ++$GLOBALS['result']['count'];
    return ['action' => $count >= $limit ? 'stale' : 'wait', 'count' => $count];
}
function rtorrentCustomConfigQuarantine(...$args) {
    $GLOBALS['result']['custom'] = false;
    $GLOBALS['result']['quarantines']++;
    return '/home/fixtur01/.rtorrent.rc.custom.broken-fixture';
}
// Marker bytes and config presence are observable without touching real accounts.
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_RT_LAUNCH'), true);
$GLOBALS['result'] = ['count' => $GLOBALS['case']['count'], 'custom' => true, 'quarantines' => 0, 'launches' => 0, 'messages' => []];
register_shutdown_function(function () { echo json_encode($GLOBALS['result']); });
$argc = 2;
$argv = ['startRtorrent', 'fixtur01'];
PHP;
        $script .= 'eval('.var_export('namespace RtorrentLaunchFixture;'.$source, true).');';
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_RT_LAUNCH' => json_encode($case + ['count' => 0, 'command' => [], 'polls' => [[], []]]),
        ]);
    }

    /** Repeated environmental failures must preserve a counter already near its limit. */
    public function testScreenErrorsNeverAdvanceExistingCounterInEitherLaunchMode(): void
    {
        foreach ([true, false] as $executor) {
            foreach (['You are not the owner of /run/screen/S-fixtur01.', 'Cannot make directory /run/screen/S-fixtur01'] as $error) {
                foreach (['stdout', 'stderr'] as $channel) {
                    // Include a zero status: explicit screen diagnostics must still stop accounting.
                    foreach ([0, 1] as $rc) {
                        for ($retry = 0; $retry < 6; $retry++) {
                            $result = $this->observe(['executor' => $executor, 'count' => 3, 'command' => [$channel => $error, 'rc' => $rc]]);
                            $this->assertSame(3, $result['count']);
                            $this->assertTrue($result['custom']);
                            $this->assertSame(0, $result['quarantines']);
                            $this->assertStringContainsString($error, implode("\n", $result['messages']));
                        }
                    }
                }
            }
        }
    }

    /** Non-screen launch failures also cannot implicate the rc file. */
    public function testOtherLauncherFailuresAndTimeoutsPreserveCustomConfig(): void
    {
        foreach ([['rc' => 127, 'stderr' => 'screen: command not found'], ['timed_out' => true]] as $command) {
            $result = $this->observe(['count' => 3, 'command' => $command]);
            $this->assertSame(3, $result['count']);
            $this->assertTrue($result['custom']);
            $this->assertStringContainsString('launcher failed', implode("\n", $result['messages']));
        }
    }

    /** A successful screen command alone does not establish that rTorrent started. */
    public function testNeverObservedProcessPreservesCustomConfigAndExistingCount(): void
    {
        $result = $this->observe(['count' => 3]);
        $this->assertSame(3, $result['count']);
        $this->assertTrue($result['custom']);
        $this->assertStringContainsString('never appeared', implode("\n", $result['messages']));
    }

    /** Delayed appearance followed by a crash retains four-attempt recovery. */
    public function testObservedCrashLoopStillQuarantinesOnFourthFailure(): void
    {
        $count = 0;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = $this->observe(['count' => $count, 'polls' => [[], [1234], []]]);
            $this->assertSame($attempt < 4, $result['custom']);
            $this->assertSame($attempt === 4 ? 1 : 0, $result['quarantines']);
            $count = $result['count'];
        }
        $this->assertSame(0, $count);
    }

    /** Healthy recovery must discard earlier config-failure evidence. */
    public function testStableProcessClearsFailureCounterWithoutQuarantine(): void
    {
        $result = $this->observe(['count' => 3, 'polls' => [[1234], [1234]], 'stable' => true]);
        $this->assertSame(0, $result['count']);
        $this->assertTrue($result['custom']);
        $this->assertSame(0, $result['quarantines']);
    }

    /** Failed prelaunch cleanup leaves both the launcher and counter untouched. */
    public function testLegacyCleanupFailureDoesNotCountAsConfigFailure(): void
    {
        $result = $this->observe(['executor' => false, 'cleanup' => false, 'count' => 3]);
        $this->assertSame(0, $result['launches']);
        $this->assertSame(3, $result['count']);
        $this->assertTrue($result['custom']);
    }
}
