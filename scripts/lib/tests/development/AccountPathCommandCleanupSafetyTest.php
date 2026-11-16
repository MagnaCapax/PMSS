<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Account command faults must release acquired resources without launching children. */
class AccountPathCommandCleanupSafetyTest extends TestCase
{
    public function testCommandFaultsCloseStdinReapChildAndRetainDiagnostics(): void
    {
        $script = <<<'PHP'
namespace AccountCommandCleanupFixture;
function observe($phase, $value = null) {
    $GLOBALS['events'][] = $phase;
    $GLOBALS['counts'][$phase] = ($GLOBALS['counts'][$phase] ?? 0) + 1;
    if (isset($GLOBALS['faults'][$phase]) && $GLOBALS['counts'][$phase] === 1) throw $GLOBALS['faults'][$phase];
    return $value;
}
// Keep identity, path validation and every process operation inside the fixture.
function function_exists($name) { return true; }
function posix_getpwnam($name) { return ['uid' => 12345]; }
function posix_geteuid() { return 12345; }
function fileowner($path) { return 12345; }
function is_dir($path) { return true; }
function pmssPathTargetIsSafe(...$args) { return true; }
function pmssPathSegmentsAreSafe(...$args) { return true; }
function pmssPathWithinResolvedRoot(...$args) { return true; }
function pmssCommandProcessGroupWrap($command, $timeout) { return $command; }
function tmpfile() { return $GLOBALS['capture'] = \fopen('php://memory', 'w+'); }
function stream_get_meta_data($handle) { return ['uri' => '/fixture/stderr']; }
function unlink($path) { return true; }
function proc_open($command, $descriptor, &$pipes, $cwd) {
    observe('launch');
    if ($GLOBALS['case'] === 'launch-false') return false;
    $pipes = [0 => $GLOBALS['stdin'] = \fopen('php://memory', 'w+')];
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function microtime($asFloat) { return 100.0 + ($GLOBALS['case'] === 'timeout' || $GLOBALS['case'] === 'timeout-log' ? ++$GLOBALS['clock'] * 10 : 0); }
function stream_set_blocking($pipe, $blocking) { return observe('blocking', $GLOBALS['case'] !== 'blocking-false'); }
function stream_select(&$read, &$write, &$except, $seconds, $micros) { return observe('select', 1); }
function fwrite($pipe, $bytes) { return observe('write', strlen($bytes)); }
function fclose($handle) {
    observe($handle === $GLOBALS['capture'] ? 'capture-close' : 'stdin-close');
    return \fclose($handle);
}
function proc_get_status($process) { return observe('status', ['running' => in_array($GLOBALS['case'], ['timeout', 'timeout-log'], true)]); }
function proc_terminate($process) { return observe('terminate', $GLOBALS['case'] !== 'write+terminate-false'); }
function pmssCommandTimeoutTerminate($process) { return observe('group-terminate', 'SIGTERM'); }
function proc_close($process) {
    observe('reap');
    // Native proc_close also closes a remaining stdin pipe after a close fault.
    if (is_resource($GLOBALS['stdin'])) \fclose($GLOBALS['stdin']);
    \fclose($process);
    observe('reaped');
    return $GLOBALS['case'] === 'child-false' ? 23 : 0;
}
function pmssProcessCloseExitCode($process, $status) { return proc_close($process); }
function pmssCommandTimeoutClose($process, $command, $timeout, $started) {
    pmssCommandTimeoutTerminate($process);
    proc_close($process);
    observe('timeout-log');
    return 124;
}
function rewind($handle) { observe('rewind'); return true; }
function fread($handle, $length) { return observe('read', ' diagnostic '); }
function pmssLogControlCharactersReplace($value) { return $value; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/accountPath.php', 'AccountCommandCleanupFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['success', 'child-false', 'launch-false', 'launch', 'blocking-false', 'timeout', 'write', 'blocking', 'select',
    'stdin-close', 'status', 'reap', 'reaped', 'timeout-log', 'write+stdin-close', 'write+terminate',
    'write+group-terminate', 'write+reaped', 'write+terminate-false'];
foreach ([0, 5] as $timeout) {
    foreach ($cases as $case) {
        if (($timeout === 0 && in_array($case, ['blocking-false', 'timeout', 'blocking', 'select', 'status', 'timeout-log', 'write+group-terminate'], true))
            || ($timeout > 0 && in_array($case, ['write+terminate', 'write+terminate-false'], true))) continue;
        foreach ([new \RuntimeException($case), new \Error($case)] as $fault) {
            $GLOBALS['case'] = $case; $GLOBALS['events'] = $GLOBALS['counts'] = $GLOBALS['faults'] = [];
            $GLOBALS['capture'] = $GLOBALS['stdin'] = $GLOBALS['process'] = null;
            $GLOBALS['clock'] = 0; $caught = null; $ok = null; $failure = 'stale'; $timedOut = true;
            foreach (explode('+', $case) as $phase) if ($phase !== 'terminate-false')
                $GLOBALS['faults'][$phase] = $fault instanceof \Error ? new \Error($phase) : new \RuntimeException($phase);
            try { $ok = pmssAccountPathRun('fixtur01', '/fixture/home', ['/fixture/home/target'],
                'fixture-command', in_array($case, ['timeout', 'timeout-log'], true) ? null : 'payload', $timeout, $timedOut, $failure); }
            catch (\Throwable $error) { $caught = $error; }
            $open = array_map('is_resource', [$GLOBALS['capture'], $GLOBALS['stdin'], $GLOBALS['process']]);
            $results[] = [$timeout, $case, $ok, $caught === end($GLOBALS['faults']), $open, $failure, $timedOut, $GLOBALS['events']];
            // Keep a failing implementation from leaking the fixture's memory streams.
            foreach ([$GLOBALS['capture'], $GLOBALS['stdin'], $GLOBALS['process']] as $handle) if (is_resource($handle)) \fclose($handle);
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(58, count($results));
        foreach ($results as $row) {
            [$timeout, $case, , , , , , $events] = $row;
            $label = $timeout.':'.$case;
            $ordinary = in_array($case, ['success', 'child-false', 'launch-false', 'blocking-false', 'timeout'], true);
            $this->pmssAssertArraySubsetSame([4 => [false, false, false], 3 => !$ordinary,
                2 => $ordinary ? $case === 'success' : null,
                6 => in_array($case, ['timeout', 'timeout-log'], true),
                5 => ['success' => '', 'child-false' => 'stage=child rc=23 stderr=diagnostic',
                    'blocking-false' => 'stage=stdin rc=0 stderr=diagnostic', 'timeout' => 'stage=timeout rc=124 stderr=diagnostic',
                ][$case] ?? 'stage=launch rc=not-started stderr=diagnostic',
            ], $row, $label.':');
            $this->assertSame(['rewind', 'read', 'capture-close'], array_slice($events, -3), $label);
            $signals = array_values(array_intersect($events, ['terminate', 'group-terminate']));
            $shouldSignal = (!$ordinary && !in_array($case, ['launch', 'reaped', 'timeout-log'], true)) || in_array($case, ['blocking-false', 'timeout', 'timeout-log'], true);
            $this->assertSame($shouldSignal ? [$timeout > 0 ? 'group-terminate' : 'terminate'] : [], $signals, $label);
            $this->assertSame(in_array($case, ['launch-false', 'launch'], true) ? 0 : ($case === 'reap' ? 2 : 1), count(array_keys($events, 'reap', true)), $label);
            // Every acquired child is reaped before the private diagnostic capture closes.
            if (!in_array($case, ['launch-false', 'launch'], true))
                $this->assertSame('reaped', $events[count($events) - ($case === 'timeout' || $case === 'timeout-log' ? 5 : 4)], $label);
        }
    }
}
