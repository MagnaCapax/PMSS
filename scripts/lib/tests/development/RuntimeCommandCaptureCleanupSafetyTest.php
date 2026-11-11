<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Capture faults use memory streams; no child, signal, clock delay or host I/O. */
class RuntimeCommandCaptureCleanupSafetyTest extends TestCase
{
    public function testReadyChildIsReleasedAfterObservationAndFinalizationFaults(): void
    {
        $script = <<<'PHP'
namespace CommandCaptureCleanupFixture;
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
}
function pmssCommandProcessGroupWrap($command, $timeout) { return $command; }
function pmssProcessPipeDescriptorSpec() { return [0 => [], 1 => [], 2 => []]; }
function proc_open($command, $descriptor, &$pipes, $cwd = null, $env = null) {
    observe('open');
    foreach ($descriptor as $index => $unused) $pipes[$index] = $GLOBALS['handles'][] = \fopen('php://memory', 'w+');
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function get_resource_type($handle) { return 'process'; }
function stream_set_blocking($stream, $mode) { return true; }
function microtime($asFloat) { observe('clock'); return 10.0; }
function pmssCommandOutputPipesDrain(...$unused) {
    observe('drain');
    return ['stdout' => 'out', 'stderr' => 'err', 'timed_out' => $GLOBALS['timeout']];
}
function pmssProcessPipesClose($pipes) {
    foreach ($pipes as $pipe) if (is_resource($pipe)) \fclose($pipe);
    observe('pipes-close');
}
function proc_get_status($process) {
    observe('status');
    return ['running' => isset($GLOBALS['faults']['sleep']), 'exitcode' => 7];
}
function usleep($delay) { observe('sleep'); }
function pmssCommandTimeoutTerminate($process) {
    $GLOBALS['cleanup'] = true;
    observe('terminate');
}
function proc_close($process) {
    if (!$GLOBALS['cleanup']) observe('exit-close');
    \fclose($process);
    observe($GLOBALS['cleanup'] ? 'reap' : 'after-close');
    return -1;
}
function pmssCommandTimeoutClose($process, ...$unused) {
    observe('timeout-close');
    \fclose($process);
    observe('timeout-log');
    return 124;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandProcess.php', 'CommandCaptureCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_CAPTURE_CLEANUP'), true) as [$inherited, $seconds, $timeout, $phases, $type]) {
    $GLOBALS['events'] = $GLOBALS['handles'] = $GLOBALS['faults'] = [];
    $GLOBALS['process'] = null; $GLOBALS['cleanup'] = false; $GLOBALS['timeout'] = $timeout;
    foreach ($phases as $phase) $GLOBALS['faults'][$phase] = new $type($phase.' failure');
    $result = $caught = $expected = null;
    try {
        $result = pmssCommandProcessCapture('fixture-command', 'fixture-command', $seconds,
            0, false, '', 1, false, '', null, null, $inherited);
    } catch (\Throwable $error) { $caught = $error; }
    foreach ($GLOBALS['events'] as $phase) $expected = $GLOBALS['faults'][$phase] ?? $expected;
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $rows[] = [$result, $caught === $expected, $live, $GLOBALS['events']];
    // Observe leaks first, then release a regressed implementation's fake resources.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $cases = $expected = [];
        foreach ([false, true] as $inherited) {
            foreach ([0, 5] as $seconds) {
                $start = ['open', 'clock'];
                $ready = array_merge($start, $inherited ? [] : ['drain', 'pipes-close']);
                $polled = array_merge($ready, $inherited || $seconds > 0 ? ['status'] : []);
                $normal = array_merge($polled, ['exit-close', 'after-close']);
                $cases[] = [$inherited, $seconds, false, [], ''];
                $expected[] = [['rc' => $inherited || $seconds > 0 ? 7 : -1,
                    'stdout' => $inherited ? '' : 'out', 'stderr' => $inherited ? '' : 'err',
                    'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => false], true, false, $normal];
                $faultTraces = ['clock' => $start, 'exit-close' => array_merge($polled, ['exit-close']),
                    'after-close' => $normal];
                if (!$inherited) $faultTraces += ['drain' => array_merge($start, ['drain']), 'pipes-close' => $ready];
                if ($inherited || $seconds > 0) {
                    $faultTraces += ['status' => $polled,
                        'sleep' => array_merge($polled, $seconds > 0 ? ['clock', 'sleep'] : ['sleep'])];
                }
                // Unbounded piped capture reaches the timeout finalizer directly after draining.
                if (!$inherited) $faultTraces += ['timeout-close' => array_merge($ready, ['timeout-close']),
                    'timeout-log' => array_merge($ready, ['timeout-close', 'timeout-log'])];
                foreach (['RuntimeException', 'Error'] as $type) {
                    foreach ($faultTraces as $phase => $trace) {
                        $closed = in_array($phase, ['after-close', 'timeout-log'], true);
                        foreach ($closed ? [[]] : [[], ['terminate'], ['reap'], ['terminate', 'reap']] as $extra) {
                            $phases = array_merge([$phase], $extra);
                            $cases[] = [$inherited, $seconds, strpos($phase, 'timeout-') === 0, $phases, $type];
                            $expected[] = [null, true, false, array_merge($trace, ['pipes-close'],
                                $closed ? [] : ['terminate', 'reap'])];
                        }
                    }
                }
            }
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_CAPTURE_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        foreach ($cases as $index => $case) $this->assertSame($expected[$index], $rows[$index], json_encode($case));
    }
}
