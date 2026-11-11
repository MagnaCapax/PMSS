<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Pipe setup faults use memory streams; no command or signal reaches the host. */
class RuntimeCommandFailureCleanupSafetyTest extends TestCase
{
    public function testFailedPipeSetupAttemptsEveryCleanupStage(): void
    {
        $script = <<<'PHP'
namespace CommandFailureCleanupFixture;
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
}
function pmssCommandProcessGroupWrap($command, $timeout) { return $command; }
function proc_open($command, $descriptor, &$pipes, $cwd, $env) {
    observe('open');
    $pipes = [];
    foreach ($descriptor as $index => $unused) {
        if ($GLOBALS['case'] === 'missing-'.$index) continue;
        if ($GLOBALS['case'] === 'invalid-'.$index) { $pipes[$index] = false; continue; }
        $pipes[$index] = $handle = \fopen('php://memory', 'w+');
        $GLOBALS['handles'][] = $handle;
        $GLOBALS['names'][(int) $handle] = $index;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function stream_set_blocking($stream, $mode) {
    $index = $GLOBALS['names'][(int) $stream];
    observe('blocking-'.$index);
    return $GLOBALS['case'] !== 'blocking-'.$index;
}
function fclose($handle) {
    $index = $GLOBALS['names'][(int) $handle];
    \fclose($handle);
    observe('close-'.$index);
    return !$GLOBALS['returnFalse'];
}
function proc_terminate($process) { observe('terminate'); return !$GLOBALS['returnFalse']; }
function proc_close($process) {
    \fclose($process);
    observe('reap');
    return -1;
}
PHP;
        // Keep the real all-pipe cleanup traversal inside the process fixture.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandPipes.php', 'CommandFailureCleanupFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandProcess.php', 'CommandFailureCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_COMMAND_FAILURE_CLEANUP'), true) as [$case, $phases, $type, $returnFalse]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['returnFalse'] = $returnFalse;
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['handles'] = $GLOBALS['names'] = [];
    $GLOBALS['process'] = null;
    foreach ($phases as $phase) $GLOBALS['faults'][$phase] = new $type($phase.' failure');
    $result = $caught = null;
    try {
        $result = pmssCommandPipedCapture('fixture-command', 'fixture-command', 0,
            0, false, 'launch failure', 17);
    } catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    // Cleanup faults supersede setup faults; within pipe cleanup, the first fault wins.
    $expected = null;
    if ($case === 'setup') {
        foreach (['close-0', 'blocking-1', 'blocking-2'] as $phase) {
            if (isset($GLOBALS['faults'][$phase])) { $expected = $GLOBALS['faults'][$phase]; break; }
        }
    }
    foreach (['close-0', 'close-1', 'close-2'] as $phase) {
        if ($case === 'setup' && $phase === 'close-0') continue;
        if (isset($GLOBALS['faults'][$phase])) { $expected = $GLOBALS['faults'][$phase]; break; }
    }
    $expected = $GLOBALS['faults']['reap'] ?? $GLOBALS['faults']['terminate'] ?? $expected;
    $rows[] = [$result, $caught === $expected, $live, $GLOBALS['events']];
    // Release fake resources only after observing any regression's leaked handles.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $cases = [];
        foreach (['missing-0', 'missing-1', 'missing-2', 'invalid-0', 'invalid-1', 'invalid-2',
            'blocking-1', 'blocking-2'] as $case) {
            foreach ([false, true] as $returnFalse) $cases[] = [$case, [], '', $returnFalse];
        }
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ([['close-0'], ['close-1'], ['close-2'], ['terminate'], ['reap'],
                ['close-0', 'close-1'], ['close-1', 'terminate'], ['terminate', 'reap'],
                ['close-0', 'terminate', 'reap']] as $phases) {
                $cases[] = [in_array('close-2', $phases, true) ? 'missing-0' : 'missing-2', $phases, $type, false];
            }
            // Nonblocking setup fails after stdin has already closed normally.
            $cases[] = ['blocking-2', ['close-1'], $type, false];
            // A thrown setup operation must reach the same cleanup as a false result.
            foreach (['close-0', 'blocking-1', 'blocking-2'] as $phase) {
                $cases[] = ['setup', [$phase], $type, false];
                foreach (['close-1', 'close-2', 'terminate', 'reap'] as $cleanupPhase) {
                    $cases[] = ['setup', [$phase, $cleanupPhase], $type, false];
                }
                $cases[] = ['setup', [$phase, 'close-1', 'close-2', 'terminate', 'reap'], $type, false];
            }
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_COMMAND_FAILURE_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        foreach ($cases as $index => [$case, $phases, $type, $returnFalse]) {
            $events = ['open'];
            if ($case === 'setup' || strpos($case, 'blocking-') === 0) {
                $events[] = 'close-0';
                if (!in_array('close-0', $phases, true)) {
                    $events[] = 'blocking-1';
                    if ($case !== 'blocking-1' && !in_array('blocking-1', $phases, true)) $events[] = 'blocking-2';
                }
            }
            foreach (range(0, 2) as $pipe) {
                if ($case !== 'missing-'.$pipe && $case !== 'invalid-'.$pipe
                    && !in_array('close-'.$pipe, $events, true)) $events[] = 'close-'.$pipe;
            }
            $events[] = 'terminate';
            $events[] = 'reap';
            $result = $phases === [] ? ['rc' => 17, 'stdout' => '', 'stderr' => 'proc_open pipes unavailable',
                'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => true] : null;
            $this->assertSame([$result, true, false, $events], $rows[$index], json_encode($cases[$index]));
        }
    }
}
