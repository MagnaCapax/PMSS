<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Account command faults use memory streams; no child is launched or signalled. */
class AccountAgentCommandCleanupSafetyTest extends TestCase
{
    public function testCommandFaultsAttemptEveryOwnedCleanupStage(): void
    {
        $script = <<<'PHP'
namespace AccountAgentCommandCleanupFixture;
function fault($key) {
    if (isset($GLOBALS['faults'][$key])) {
        $GLOBALS['lastFault'] = $GLOBALS['faults'][$key];
        throw $GLOBALS['lastFault'];
    }
}
function proc_open($command, $descriptor, &$pipes, $cwd, $env) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['launch'] = [$command, $descriptor, $cwd, $env];
    if ($GLOBALS['case'] === 'launch-failed') return false;
    foreach ([0, 1] as $index) {
        $pipes[$index] = $GLOBALS['handles'][$index] = \fopen('php://memory', 'w+');
        $GLOBALS['names'][(int) $pipes[$index]] = 'close-'.$index;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fclose($handle) {
    $phase = $GLOBALS['names'][(int) $handle];
    $GLOBALS['events'][] = $phase;
    $attempt = $GLOBALS['attempts'][$phase] = ($GLOBALS['attempts'][$phase] ?? 0) + 1;
    fault($phase.'-'.$attempt.'-before');
    \fclose($handle);
    fault($phase.'-'.$attempt.'-after');
    return true;
}
function stream_get_contents($handle) {
    $GLOBALS['events'][] = 'read';
    fault('read');
    return $GLOBALS['case'] === 'read-failed' ? false : "fixture output\n";
}
function proc_terminate($process) {
    $GLOBALS['events'][] = 'terminate';
    fault('terminate');
    return $GLOBALS['case'] !== 'terminate-failed';
}
function proc_close($process) {
    $GLOBALS['events'][] = 'reap';
    $attempt = $GLOBALS['attempts']['reap'] = ($GLOBALS['attempts']['reap'] ?? 0) + 1;
    fault('reap-'.$attempt.'-before');
    \fclose($process);
    fault('reap-'.$attempt.'-after');
    return 7;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('etc/skel/bin/pmssAccountAgent.php', 'AccountAgentCommandCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_AGENT_COMMAND_CLEANUP'), true) as [$case, $keys, $type]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['handles'] = $GLOBALS['names'] = $GLOBALS['attempts'] = [];
    $GLOBALS['process'] = $GLOBALS['lastFault'] = null;
    foreach ($keys as $key) $GLOBALS['faults'][$key] = new $type($key);
    $result = $caught = null;
    try { $result = pmssAgentRun(['fixture-command', 'two words'], ['PMSS_FIXTURE' => 'yes']); }
    catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $rows[] = [$result, $caught === $GLOBALS['lastFault'], $live, $GLOBALS['events'], $GLOBALS['launch']];
    // Observe leaks before releasing the fixture's fake resources.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $normal = ['open', 'close-0', 'read', 'close-1', 'reap'];
        $readFault = ['open', 'close-0', 'read', 'close-1', 'terminate', 'reap'];
        $cases = [['success', [], '', $normal, [7, "fixture output\n"]],
            ['read-failed', [], '', $normal, [7, '']],
            ['launch-failed', [], '', ['open'], [1, 'Could not launch command']]];
        // Each stage fails before or after releasing its resource; combined faults check precedence.
        $faultCases = [
            [['close-0-1-before'], ['open', 'close-0', 'close-0', 'close-1', 'terminate', 'reap']],
            [['close-0-1-after'], ['open', 'close-0', 'close-1', 'terminate', 'reap']],
            [['read'], $readFault],
            [['close-1-1-before'], ['open', 'close-0', 'read', 'close-1', 'close-1', 'terminate', 'reap']],
            [['close-1-1-after'], $readFault],
            [['reap-1-before'], array_merge($normal, ['terminate', 'reap'])],
            [['reap-1-after'], $normal],
            [['read', 'close-1-1-after'], $readFault],
            [['read', 'terminate'], $readFault],
            [['read', 'reap-1-after'], $readFault],
            [['read', 'close-1-1-after', 'terminate', 'reap-1-after'], $readFault],
            [['close-0-1-before', 'close-0-2-after'], ['open', 'close-0', 'close-0', 'close-1', 'terminate', 'reap']],
        ];
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ($faultCases as [$keys, $events]) $cases[] = ['fault', $keys, $type, $events, null];
            $cases[] = ['terminate-failed', ['read'], $type, $readFault, null];
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_AGENT_COMMAND_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        $launch = ["'fixture-command' 'two words' 2>&1", [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], null, ['PMSS_FIXTURE' => 'yes']];
        foreach ($cases as $index => [$case, $keys, $type, $events, $result]) {
            $this->assertSame([$result, true, false, $events, $launch], $rows[$index], json_encode($cases[$index]));
        }
    }
}
