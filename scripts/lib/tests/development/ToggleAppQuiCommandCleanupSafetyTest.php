<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Memory-only CLI capture faults in etc/skel/www/toggleAppRuntime.php. */
class ToggleAppQuiCommandCleanupSafetyTest extends TestCase
{
    public function testCapturePreservesResultsAndCleansUpOwnedResourcesOnThrowables(): void
    {
        $script = <<<'PHP'
namespace ToggleQuiCommandFixture;
function fault($stage) {
    if (isset($GLOBALS['faults'][$stage])) {
        $GLOBALS['lastFault'] = $GLOBALS['faults'][$stage];
        throw $GLOBALS['lastFault'];
    }
}
function proc_open($command, $spec, &$pipes) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['launch'] = [$command, $spec];
    fault('open');
    if ($GLOBALS['case'] === 'open-false') return false;
    foreach ([0, 1, 2] as $slot) {
        $pipes[$slot] = $GLOBALS['handles'][$slot] = \fopen('php://memory', 'w+');
        $GLOBALS['slots'][(int) $pipes[$slot]] = $slot;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $bytes) {
    $GLOBALS['events'][] = 'write';
    $GLOBALS['input'] = $bytes;
    fault('write');
    if ($GLOBALS['case'] === 'write-false') return false;
    return $GLOBALS['case'] === 'write-short' ? 1 : strlen($bytes);
}
function stream_get_contents($handle) {
    $slot = $GLOBALS['slots'][(int) $handle];
    $GLOBALS['events'][] = 'read-'.$slot;
    fault('read-'.$slot);
    if ($GLOBALS['case'] === 'read-'.$slot.'-false') return false;
    return $slot === 1 ? "stdout\0\n" : "stderr\n";
}
function fclose($handle) {
    $stage = 'close-'.$GLOBALS['slots'][(int) $handle];
    $GLOBALS['events'][] = $stage;
    $attempt = $GLOBALS['attempts'][$stage] = ($GLOBALS['attempts'][$stage] ?? 0) + 1;
    fault($stage.'-'.$attempt.'-before');
    \fclose($handle);
    fault($stage.'-'.$attempt.'-after');
    return $GLOBALS['case'] !== 'close-false';
}
function proc_terminate($process) {
    $GLOBALS['events'][] = 'terminate';
    fault('terminate');
    return $GLOBALS['case'] !== 'terminate-false';
}
function proc_close($process) {
    $GLOBALS['events'][] = 'reap';
    $attempt = $GLOBALS['attempts']['reap'] = ($GLOBALS['attempts']['reap'] ?? 0) + 1;
    fault('reap-'.$attempt.'-before');
    \fclose($process);
    fault('reap-'.$attempt.'-after');
    return $GLOBALS['case'] === 'exit-failed' ? 9 : 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('etc/skel/www/toggleAppRuntime.php', 'ToggleQuiCommandFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_QUI_COMMAND_CLEANUP'), true) as [$case, $keys, $type]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['handles'] = $GLOBALS['slots'] = $GLOBALS['attempts'] = [];
    $GLOBALS['process'] = $GLOBALS['lastFault'] = $GLOBALS['input'] = null;
    foreach ($keys as $key) $GLOBALS['faults'][$key] = new $type($key);
    $result = $caught = null;
    try { $result = pmssToggleAppQuiAccountCommand('/fixture/qui binary', '/fixture/qui config',
        'fixtur01', 'fixture-service-secret', 'create-user'); }
    catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $rows[] = [$result, $caught === $GLOBALS['lastFault'], $live, $GLOBALS['events'],
        $GLOBALS['launch'], $GLOBALS['input']];
    // Observe leaks before releasing fake resources; no real process or signal is used.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $normal = ['open', 'write', 'close-0', 'read-1', 'read-2', 'close-1', 'close-2', 'reap'];
        $output = "stdout\0\nstderr\n";
        $cases = [['success', [], '', $normal, [true, $output]],
            ['open-false', [], '', ['open'], [false, '']]];
        // Ordinary false/short I/O results retain the existing acceptance and output bytes.
        foreach (['write-false', 'write-short', 'close-false', 'exit-failed', 'read-1-false', 'read-2-false'] as $case) {
            $bytes = $case === 'read-1-false' ? "stderr\n" : ($case === 'read-2-false' ? "stdout\0\n" : $output);
            $cases[] = [$case, [], '', $normal, [$case !== 'exit-failed', $bytes]];
        }
        $writeFault = ['open', 'write', 'close-0', 'close-1', 'close-2', 'terminate', 'reap'];
        $readFault = ['open', 'write', 'close-0', 'read-1', 'read-2', 'close-1', 'close-2', 'terminate', 'reap'];
        $faultCases = [
            [['open'], ['open']],
            [['write'], $writeFault],
            [['close-0-1-before'], ['open', 'write', 'close-0', 'close-0', 'close-1', 'close-2', 'terminate', 'reap']],
            [['close-0-1-after'], $writeFault],
            [['read-1'], ['open', 'write', 'close-0', 'read-1', 'close-1', 'close-2', 'terminate', 'reap']],
            [['read-2'], $readFault],
            [['close-1-1-before'], ['open', 'write', 'close-0', 'read-1', 'read-2', 'close-1', 'close-1', 'close-2', 'terminate', 'reap']],
            [['close-1-1-after'], $readFault],
            [['close-2-1-before'], ['open', 'write', 'close-0', 'read-1', 'read-2', 'close-1', 'close-2', 'close-2', 'terminate', 'reap']],
            [['close-2-1-after'], $readFault],
            [['reap-1-before'], array_merge($normal, ['terminate', 'reap'])],
            [['reap-1-after'], $normal],
            [['read-2', 'close-1-1-after'], $readFault],
            [['read-2', 'terminate'], $readFault],
            [['read-2', 'reap-1-after'], $readFault],
            [['write', 'close-0-1-after', 'close-1-1-after', 'close-2-1-after', 'terminate', 'reap-1-after'], $writeFault],
        ];
        // Exercise exceptions and errors before/after release, including later-fault precedence.
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ($faultCases as [$keys, $events]) $cases[] = ['fault', $keys, $type, $events, null];
            $cases[] = ['terminate-false', ['read-2'], $type, $readFault, null];
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_QUI_COMMAND_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        $launch = ["'/fixture/qui binary' 'create-user' --config-dir '/fixture/qui config' --username 'fixtur01'",
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]];
        foreach ($cases as $index => [$case, $keys, $type, $events, $result]) {
            // The separate service credential stays on stdin; argv and diagnostics contain none.
            $input = in_array('write', $events, true) ? "fixture-service-secret\n" : null;
            $this->assertSame([$result, true, false, $events, $launch, $input], $rows[$index], json_encode($cases[$index]));
        }
    }
}
