<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Simulate storage and account failures without probing the host or launching services. */
final class RtorrentSessionResetEnvironmentTest extends TestCase
{
    private function observe(array $case, bool $missing = false): array
    {
        $script = <<<'PHP'
namespace RtorrentSessionEnvironmentFixture;
function rtorrentProcessUserTargetPathIsSafe(...$args) { return $GLOBALS['case']['safe'] ?? true; }
function is_dir($path) { return $GLOBALS['case']['sessionExists'] ?? true; }
function disk_free_space($path) { $GLOBALS['spacePaths'][] = $path; return array_shift($GLOBALS['case']['space']); }
function file_exists($path) { return $GLOBALS['case']['files'] ?? true; }
function is_link($path) { return false; }
function pmssCheckRtorrentLogBoth($user, $message, $debug) { $GLOBALS['messages'][] = $message; }
function pmssUserWatchdogTimeoutSeconds($seconds) { return $seconds; }
function pmssUserWatchdogBackoffRecord(...$args) { $GLOBALS['backoff'][] = $args; }
function pmssAccountPathRun($user, $home, $paths, $command, $input, $timeout, &$timedOut) {
    $GLOBALS['commands'][] = [$user, $home, $paths, $command, $input, $timeout];
    $timedOut = $GLOBALS['case']['timeout'] ?? false;
    return $GLOBALS['case']['accountReady'] ?? true;
}
function rtorrentScgiSocketPath($user) { return '/socket'; }
function pmssCheckRtorrentCleanupStaleSocket(...$args) {}
function is_file($path) { return $path === 'started' || ($path === 'reset' && ($GLOBALS['case']['resetMarker'] ?? false)); }
function rtorrentProcessCheckFailureCountState(...$args) { return ['count' => $GLOBALS['case']['count'] ?? 3]; }
function rtorrentProcessResetSessionDirectory($home, $user, $log) {
    $GLOBALS['events'][] = 'reset'; $log('Quarantined broken session directory: session -> session.broken-fixture', true);
    return $GLOBALS['case']['resetSuccess'] ?? true;
}
function rtorrentProcessWriteStateFile($path, $value) { $GLOBALS['events'][] = 'write:'.$path; return true; }
function pmssCheckRtorrentStaggerAfterRecentReboot(...$args) { return true; }
function rtorrentProcessStart(...$args) { $GLOBALS['events'][] = 'start'; return 0; }
function pmssCheckRtorrentHandleEscalatedStartFailure(...$args) { $GLOBALS['events'][] = 'escalate'; }
function pmssUserLog($user, $message) { $GLOBALS['accountLog'][] = [$user, $message]; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/sessionResetPreflight.php', 'RtorrentSessionEnvironmentFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/watchdogMissing.php', 'RtorrentSessionEnvironmentFixture');
        $script .= <<<'PHP'
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_SESSION_ENVIRONMENT'), true);
$GLOBALS['events'] = $GLOBALS['spacePaths'] = $GLOBALS['messages'] = $GLOBALS['commands'] = $GLOBALS['backoff'] = $GLOBALS['accountLog'] = [];
if (getenv('PMSS_TEST_SESSION_MISSING') === '1') {
    pmssCheckRtorrentHandleMissingProcess('fixtur01', '/home/fixtur01', false, [], [
        'unresponsive' => 'unresponsive', 'startMarker' => 'started', 'startFailure' => 'failure',
        'sessionReset' => 'reset', 'escalation' => 'escalation',
    ], static function () {}, false, 180, 3, 6);
    $ready = null;
} else {
    $ready = pmssCheckRtorrentSessionResetEnvironmentReady('fixtur01', '/home/fixtur01', false);
}
echo json_encode([$ready, $GLOBALS['events'], $GLOBALS['spacePaths'], $GLOBALS['messages'], $GLOBALS['commands'], $GLOBALS['backoff'], $GLOBALS['accountLog']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_SESSION_ENVIRONMENT' => json_encode($case + ['space' => [1048576, 1048576]]),
            'PMSS_TEST_SESSION_MISSING' => $missing ? '1' : '0',
        ]);
    }

    public function testUnavailableOrFullStorageNeverLaunchesAccountProbe(): void
    {
        foreach ([[false], [0], [-1], [1048576, false], [1048576, 0]] as $space) {
            $result = $this->observe(['space' => $space]);
            $this->assertFalse($result[0]);
            $this->assertSame([], $result[4]);
            $this->assertStringContainsString('free space exhausted or unavailable', $result[3][0]);
        }
    }

    public function testUnsafePathNeverProbesStorageOrAccount(): void
    {
        $result = $this->observe(['safe' => false]);
        $this->assertFalse($result[0]);
        $this->assertSame([], $result[2]);
        $this->assertSame([], $result[4]);
    }

    public function testAccountProbeChecksStartupAndWritesAsTheAccountWithDeadline(): void
    {
        $result = $this->observe([]);
        $this->assertTrue($result[0]);
        $this->assertSame(['/home/fixtur01', '/home/fixtur01/session'], $result[2]);
        [$user, $home, $paths, $command, $input, $timeout] = $result[4][0];
        $this->assertSame('fixtur01', $user);
        $this->assertSame('/home/fixtur01', $home);
        $this->assertSame($result[2], $paths);
        $this->assertSame(null, $input);
        $this->assertSame(10, $timeout);
        $this->assertStringContainsAllStrings([
            "test -r '/home/fixtur01/.rtorrent.rc'", 'test -x /usr/local/bin/rtorrent',
            'command -v screen', 'test -r /etc/seedbox/config/localnet',
            'test -s /etc/seedbox/config/localnet', "test -x '/home/fixtur01/.rtorrentExecute.php'",
            "test -r '/home/fixtur01/.scriptsInc.php'", 'command -v php',
            'mktemp --', 'printf x > "$probe"', 'trap', 'rm -f -- "$probe"',
        ], $command);
    }

    public function testMissingOptionalFilesAndSessionRetainLegacyStartPrerequisites(): void
    {
        $result = $this->observe(['sessionExists' => false, 'files' => false]);
        $this->assertTrue($result[0]);
        $this->assertSame(['/home/fixtur01'], $result[2]);
        $this->assertSame($result[2], $result[4][0][2]);
        $this->assertFalse(strpos($result[4][0][3], '/etc/seedbox/config/localnet') !== false);
        $this->assertFalse(strpos($result[4][0][3], '.rtorrentExecute.php') !== false);
    }

    public function testFailedOrTimedOutAccountProbeDefersReset(): void
    {
        foreach ([false, true] as $timeout) {
            $result = $this->observe(['accountReady' => false, 'timeout' => $timeout]);
            $this->assertFalse($result[0]);
            $this->assertSame($timeout ? [['fixtur01', 'rtorrent']] : [], $result[5]);
            $this->assertStringContainsString('account startup or write preflight failed', $result[3][0]);
        }
    }

    public function testEnvironmentalFailuresKeepOriginalSessionAndExistingRetryLadder(): void
    {
        foreach ([['space' => [0]], ['accountReady' => false], ['safe' => false]] as $case) {
            $this->assertSame(['start'], $this->observe($case, true)[1]);
            $this->assertSame(['escalate'], $this->observe($case + ['count' => 6], true)[1]);
        }
    }

    public function testHealthyPreflightPreservesThresholdAndPublishesBackupPath(): void
    {
        foreach ([['count' => 2], ['resetMarker' => true]] as $case) {
            $result = $this->observe($case, true);
            $this->assertSame(['start'], $result[1]);
            $this->assertSame([], $result[4]);
        }
        $result = $this->observe([], true);
        $this->assertSame(['reset', 'write:reset', 'start'], $result[1]);
        $this->assertStringContainsString('session.broken-fixture', $result[6][0][1]);
        $this->assertSame(['reset', 'start'], $this->observe(['resetSuccess' => false], true)[1]);
    }
}
