<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdogSocketProbe.php';

/** Lock the former inline cron decision, including diagnostic bytes and side-effect order. */
class LighttpdSocketHealthCharacterizationTest extends TestCase
{
    public function testSocketDecisionTraces(): void
    {
        $probe = static function (string $path): array { return ['probe', $path]; };
        $clear = ['clear', 'account'];
        $snapshot = ['snapshot', '/home/account'];
        $record = ['record', 'account'];
        $good = ['ok' => true, 'errno' => 0, 'errstr' => '', 'attempts' => 1];
        $refused = ['ok' => false, 'errno' => 111, 'errstr' => 'refused', 'attempts' => 4];
        $timeout = ['ok' => false, 'errno' => 110, 'errstr' => 'timeout', 'attempts' => 2];
        $wait = ['action' => 'wait', 'count' => 1, 'threshold' => 3];
        $restart = ['action' => 'restart', 'count' => 3, 'threshold' => 3];
        $failedWrite = ['action' => 'wait', 'count' => 0, 'threshold' => 3];
        $prefix = "Error when attempting to connect to socket /socket-0: 111, refused (after 4 attempts)\n";
        $deferred = "Deferring lighttpd restart for user: account; waiting for sustained socket failure.\n";
        // Expected traces come from the original cron block: first failure stops the scan.
        $cases = [
            'missing-process' => [false, ['/socket-0'], [], [], $wait,
                [true, "php-cgi not running, for user: account. Killing lighttpd instances.\n", [$clear]]],
            'no-sockets' => [true, [], [], [], $wait, [false, '', [$clear]]],
            'healthy' => [true, ['/socket-0', '/socket-1'], [$good, $good], [], $wait,
                [false, '', [$probe('/socket-0'), $probe('/socket-1'), $clear]]],
            'wait' => [true, ['/socket-0', '/socket-1'], [$refused, $good], [], $wait,
                [false, $prefix."php-cgi socket probe failed for user: account; consecutive failures 1/3.\n".$deferred,
                    [$probe('/socket-0'), $snapshot, $record]]],
            'restart' => [true, ['/socket-0', '/socket-1'], [$refused, $good], [], $restart,
                [true, $prefix."php-cgi socket probe failed for user: account; consecutive failures 3/3.\n"
                    ."php-cgi, for user: account. Killing lighttpd instances.\n", [$probe('/socket-0'), $snapshot, $record]]],
            'failed-persistence' => [true, ['/socket-0'], [$refused], [], $failedWrite,
                [false, $prefix."php-cgi socket probe failed for user: account; consecutive failures 0/3.\n".$deferred,
                    [$probe('/socket-0'), $snapshot, $record]]],
            'timeout' => [true, ['/socket-0'], [$timeout], ['/other'], $wait,
                [false, "Error when attempting to connect to socket /socket-0: 110, timeout (after 2 attempts)\n"
                    ."php-cgi socket probe failed for user: account; consecutive failures 1/3.\n".$deferred,
                    [$probe('/socket-0'), $record]]],
            'stale-index' => [true, ['/socket-0', '/socket-1', '/socket-2'], [$good, $refused, $good], ['/other-0', '/other-1', '/other-2'], $wait,
                [false, "Configured php-cgi socket /socket-1 refused the probe, but live listener coverage is healthy for user: account; skipping restart.\n",
                    [$probe('/socket-0'), $probe('/socket-1'), $snapshot, $clear, $clear]]],
            'partial-coverage' => [true, ['/socket-0', '/socket-1'], [$refused], ['/other'], $wait,
                [false, $prefix."php-cgi socket probe failed for user: account; consecutive failures 1/3.\n".$deferred,
                    [$probe('/socket-0'), $snapshot, $record]]],
        ];
        $script = <<<'PHP'
namespace SocketHealthFixture;
function pmssLighttpdWatchdogSocketProbeWithRetry($path) {
    $GLOBALS['trace'][] = ['probe', $path];
    return array_shift($GLOBALS['probes']);
}
function pmssLighttpdWatchdogListeningSocketSnapshot($home) {
    $GLOBALS['trace'][] = ['snapshot', $home];
    return ['ok' => true, 'paths' => $GLOBALS['listeners']];
}
function pmssLighttpdWatchdogRecordSocketFailure($user) {
    $GLOBALS['trace'][] = ['record', $user];
    return $GLOBALS['failure'];
}
function pmssLighttpdWatchdogClearSocketFailure($user) { $GLOBALS['trace'][] = ['clear', $user]; }
PHP;
        $script .= 'require '.var_export($this->pmssRepoPath('scripts/lib/lighttpd/watchdogSocketProbe.php'), true).';';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/watchdogSocketHealth.php', 'SocketHealthFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_SOCKET_CASES'), true) as $name => $case) {
    [$running, $paths, $GLOBALS['probes'], $GLOBALS['listeners'], $GLOBALS['failure']] = $case;
    $GLOBALS['trace'] = [];
    ob_start();
    $restart = pmssLighttpdWatchdogSocketRestartRequired('account', '/home/account', $paths, $running);
    $results[$name] = [$restart, ob_get_clean(), $GLOBALS['trace']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SOCKET_CASES' => json_encode($cases)]);
        foreach ($cases as $name => $case) $this->assertSame($case[5], $results[$name], $name);
    }

    public function testProbeExceptionsStopWithoutRecordingOrClearingFailureState(): void
    {
        $script = <<<'PHP'
namespace SocketHealthExceptionFixture;
function pmssLighttpdWatchdogSocketProbeWithRetry($path) { throw $GLOBALS['failure']; }
function pmssLighttpdWatchdogRecordSocketFailure($user) { throw new \LogicException('unexpected write'); }
function pmssLighttpdWatchdogClearSocketFailure($user) { throw new \LogicException('unexpected clear'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/watchdogSocketHealth.php', 'SocketHealthExceptionFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([new \RuntimeException('probe'), new \Error('probe')] as $failure) {
    $GLOBALS['failure'] = $failure;
    ob_start();
    try { pmssLighttpdWatchdogSocketRestartRequired('account', '/home/account', ['/socket-0'], true); }
    catch (\Throwable $caught) { $results[] = [$caught === $failure, ob_get_clean()]; }
}
echo json_encode($results);
PHP;
        $this->assertSame([[true, ''], [true, '']], $this->pmssRunInlinePhpJson($script));
    }

    public function testFailureStateRequiresCompletePersistenceBeforeRestart(): void
    {
        $script = <<<'PHP'
namespace SocketStateFixture;
function pmssValidateUsername($user) { return $user === 'account'; }
function pmssPathAbsoluteStringIsSafe($path) { return true; }
function pmssPathTargetIsSafe(...$args) { return true; }
function pmssDirEnsureExists($dir, $mode) { $GLOBALS['trace'][] = ['directory', $dir, $mode]; return $GLOBALS['case'][0]; }
function pmssReadRegularFileInt($path) { $GLOBALS['trace'][] = ['read', $path]; return $GLOBALS['case'][1]; }
function pmssFileWriteComplete($path, $payload, $flags) {
    $GLOBALS['trace'][] = ['write', $path, $payload, $flags];
    return $GLOBALS['case'][2];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/watchdogSocketState.php', 'SocketStateFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([[false, 2, true], [true, 2, false], [true, 0, true], [true, 2, true], [true, -2, true]] as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['trace'] = [];
    $results[] = [pmssLighttpdWatchdogRecordSocketFailure('account', ['runtimeDir' => '/runtime', 'threshold' => 3]), $GLOBALS['trace']];
}
echo json_encode($results);
PHP;
        $path = '/runtime/checkLighttpdInstances-socket-account.count';
        $directory = ['directory', '/runtime', 0755];
        $read = ['read', $path];
        $results = $this->pmssRunInlinePhpJson($script);
        foreach ([['wait', 0, [$directory]], ['wait', 0, [$directory, $read, ['write', $path, '3', LOCK_EX]]],
            ['wait', 1, [$directory, $read, ['write', $path, '1', LOCK_EX]]],
            ['restart', 3, [$directory, $read, ['write', $path, '3', LOCK_EX]]],
            ['wait', 1, [$directory, $read, ['write', $path, '1', LOCK_EX]]]] as $i => $case) {
            $this->assertSame([['action' => $case[0], 'count' => $case[1], 'threshold' => 3], $case[2]], $results[$i]);
        }
    }
}
