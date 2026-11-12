<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise only command capture from etc/skel/www/welcome.php, without launching Pikkumies. */
class WelcomeSupportCommandCleanupSafetyTest extends TestCase
{
    public function testEveryOwnedCleanupStageIsAttemptedAfterIoFaults(): void
    {
        $script = <<<'PHP'
namespace WelcomeSupportCommandFixture;
function fault($stage) {
    if (isset($GLOBALS['faults'][$stage])) {
        $GLOBALS['lastFault'] = $GLOBALS['faults'][$stage];
        throw $GLOBALS['lastFault'];
    }
}
function proc_open($command, $descriptor, &$pipes) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['launch'] = [$command, $descriptor];
    fault('open');
    if ($GLOBALS['case'] === 'launch-false') return false;
    foreach ([0, 1, 2] as $index) {
        $pipes[$index] = $GLOBALS['handles'][$index] = \fopen('php://memory', 'w+');
        $GLOBALS['names'][(int) $pipes[$index]] = $index;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $data) {
    $GLOBALS['events'][] = 'write';
    $GLOBALS['input'] = [$GLOBALS['names'][(int) $handle], $data];
    fault('write');
    return $GLOBALS['case'] === 'write-false' ? false : strlen($data);
}
function stream_get_contents($handle, $length) {
    $index = $GLOBALS['names'][(int) $handle];
    $GLOBALS['events'][] = 'read-'.$index;
    $GLOBALS['reads'][] = [$index, $length];
    fault('read-'.$index);
    if ($GLOBALS['case'] === 'read-false') return false;
    return $index === 1 ? ($GLOBALS['case'] === 'stderr' ? '' : " \tfixture output\n") : " fixture error\n";
}
function fclose($handle) {
    $stage = 'close-'.$GLOBALS['names'][(int) $handle];
    $GLOBALS['events'][] = $stage;
    \fclose($handle);
    fault($stage);
    return true;
}
function proc_close($process) {
    $GLOBALS['events'][] = 'reap';
    \fclose($process);
    fault('reap');
    return $GLOBALS['case'] === 'zero-exit' ? 0 : 7;
}
PHP;
        // The page's top level performs requests; isolate its existing command function only.
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeHomeSupportPikkumiesRun(');
        $end = strpos($source, '/** What the customer reads for each `pikkumies ask` exit code');
        $this->assertTrue($start !== false && $end !== false && $end > $start);
        $script .= substr($source, $start, $end - $start);
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_WELCOME_COMMAND_CLEANUP'), true) as [$case, $keys, $type]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['handles'] = $GLOBALS['names'] = $GLOBALS['reads'] = [];
    $GLOBALS['process'] = $GLOBALS['lastFault'] = $GLOBALS['input'] = null;
    foreach ($keys as $key) $GLOBALS['faults'][$key] = new $type($key);
    $result = $caught = null;
    try { $result = pmssWelcomeHomeSupportPikkumiesRun('/fixture/pikkumies', ['ask', 'two words'], "fixture request\n", 20); }
    catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $rows[] = [$result, $caught === $GLOBALS['lastFault'], $live, $GLOBALS['events'],
        $GLOBALS['launch'], $GLOBALS['input'], $GLOBALS['reads']];
    // Measure leaked resources before releasing anything left by a failing implementation.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $normal = ['open', 'write', 'close-0', 'read-1', 'read-2', 'close-1', 'close-2', 'reap'];
        $writeFault = ['open', 'write', 'close-0', 'close-1', 'close-2', 'reap'];
        $readFault = ['open', 'write', 'close-0', 'read-1', 'close-1', 'close-2', 'reap'];
        $cases = [
            ['success', [], '', $normal, [7, 'fixture output']],
            ['zero-exit', [], '', $normal, [0, 'fixture output']],
            ['stderr', [], '', $normal, [7, 'fixture error']],
            ['write-false', [], '', $normal, [7, 'fixture output']],
            ['read-false', [], '', $normal, [7, '']],
            ['launch-false', [], '', ['open'], [-1, '']],
        ];
        // Both throwable families cover every I/O/cleanup boundary and later-fault precedence.
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ([
                [['open'], ['open']],
                [['write'], $writeFault],
                [['close-0'], $writeFault],
                [['read-1'], $readFault],
                [['read-2'], $normal],
                [['close-1'], $normal],
                [['close-2'], $normal],
                [['reap'], $normal],
                [['write', 'close-0', 'close-1', 'close-2', 'reap'], $writeFault],
                [['read-1', 'close-1'], $readFault],
            ] as [$keys, $events]) $cases[] = ['fault', $keys, $type, $events, null];
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_WELCOME_COMMAND_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        $launch = ["timeout 20 '/fixture/pikkumies' 'ask' 'two words'",
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]];
        foreach ($cases as $index => [$case, $keys, $type, $events, $result]) {
            $reads = [];
            foreach ([1 => 65536, 2 => 4096] as $pipe => $limit) {
                if (in_array('read-'.$pipe, $events, true)) $reads[] = [$pipe, $limit];
            }
            $input = in_array('write', $events, true) ? [0, "fixture request\n"] : null;
            $this->assertSame([$result, true, false, $events, $launch, $input, $reads],
                $rows[$index], json_encode([$case, $keys, $type]));
        }
    }
}
