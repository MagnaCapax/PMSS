<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Heartbeat ownership faults use memory streams without spawning or signalling a child. */
final class StdoutHeartbeatCleanupSafetyTest extends TestCase
{
    public function testStartupAndStopFaultsStillReleaseTheChild(): void
    {
        $script = <<<'PHP'
namespace HeartbeatCleanupFixture;
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
}
function getmypid() { return 1234; }
function proc_open($command, $descriptor, &$pipes) {
    observe('open');
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function get_resource_type($handle) { return 'process'; }
function register_shutdown_function($callback) { observe('register'); }
function proc_get_status($process) { observe('status'); return ['pid' => 42, 'running' => true]; }
function proc_terminate($process) {
    $GLOBALS['cleared'][] = !isset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
    observe('terminate');
    return true;
}
function proc_close($process) {
    $GLOBALS['cleared'][] = !isset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
    \fclose($process);
    observe('close');
    return 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/stdoutHeartbeat.php', 'HeartbeatCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_HEARTBEAT_CLEANUP'), true) as [$phase, $type, $cleanupPhase, $cleanupType]) {
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['cleared'] = [];
    $GLOBALS['process'] = null;
    unset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
    if ($phase !== '') $GLOBALS['faults'][$phase] = new $type($phase.' failure');
    if ($cleanupPhase !== '') $GLOBALS['faults'][$cleanupPhase] = new $cleanupType($cleanupPhase.' failure');
    $pid = $again = $published = $caught = null;
    try {
        $pid = pmssStdoutHeartbeatStart();
        $published = isset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
        $again = pmssStdoutHeartbeatStart();
        pmssStdoutHeartbeatStop();
    } catch (\Throwable $error) { $caught = $error; }
    // Cleanup must clear the global even when its last stage throws.
    $remaining = isset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
    $live = is_resource($GLOBALS['process']);
    $events = $GLOBALS['events'];
    $repeatError = false;
    try { pmssStdoutHeartbeatStop(); } catch (\Throwable $error) { $repeatError = true; }
    $expected = $GLOBALS['faults'][$cleanupPhase] ?? $GLOBALS['faults'][$phase] ?? null;
    $rows[] = [$pid, $again, $published, $remaining, $live, $events, $GLOBALS['cleared'],
        $caught === $expected, $caught === null ? null : $caught->getMessage(),
        !$repeatError && $events === $GLOBALS['events']];
    // Release a regressed implementation's fake resource after recording the leak.
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
    unset($GLOBALS['PMSS_STDOUT_HEARTBEAT_PROCESS']);
}
echo json_encode($rows);
PHP;
        $cases = [['', '', '', '']];
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach (['register', 'status', 'terminate', 'close'] as $phase) {
                $cases[] = [$phase, $type, '', ''];
            }
            foreach (['RuntimeException', 'Error'] as $cleanupType) {
                foreach (['register', 'status'] as $phase) {
                    foreach (['terminate', 'close'] as $cleanupPhase) {
                        $cases[] = [$phase, $type, $cleanupPhase, $cleanupType];
                    }
                }
                $cases[] = ['terminate', $type, 'close', $cleanupType];
            }
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_HEARTBEAT_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        foreach ($cases as $index => [$phase, $type, $cleanupPhase, $cleanupType]) {
            $started = $phase !== 'register' && $phase !== 'status';
            $events = ['open', 'register'];
            if ($phase !== 'register') $events[] = 'status';
            $events[] = 'terminate';
            $events[] = 'close';
            $failure = $cleanupPhase !== '' ? $cleanupPhase : $phase;
            $this->assertSame([$started ? 42 : null, null, $started ? true : null, false, false,
                $events, [true, true], true, $failure === '' ? null : $failure.' failure', true],
                $rows[$index], implode(':', $cases[$index]));
        }
    }
}
