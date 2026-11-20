<?php
/** Verify complete writes and child cleanup in scripts/lib/cgroup/userSliceMemsw.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserSliceMemswWriteSafetyTest extends TestCase
{
    public function testWriteFailuresStillCloseStdinAndReapTheChild(): void
    {
        $script = <<<'PHP'
namespace MemswWriteFixture;
// Memory streams stand in for both handles; no command or sysfs write runs.
function proc_open($command, $descriptor, &$pipes) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['launch'] = [$command, $descriptor];
    if ($GLOBALS['case'] === 'launch-false') return false;
    if ($GLOBALS['case'] === 'launch-throw') throw $GLOBALS['faults']['launch'];
    $pipes = [$GLOBALS['pipe'] = \fopen('php://memory', 'w+')];
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $input) {
    $GLOBALS['events'][] = 'write';
    $GLOBALS['input'] = $input;
    if (isset($GLOBALS['faults']['write'])) throw $GLOBALS['faults']['write'];
    $limits = ['short' => strlen($input) - 1, 'zero' => 0, 'write-false' => false];
    return array_key_exists($GLOBALS['case'], $limits) ? $limits[$GLOBALS['case']] : strlen($input);
}
function fclose($handle) {
    $GLOBALS['events'][] = 'close';
    \fclose($handle);
    if (isset($GLOBALS['faults']['close'])) throw $GLOBALS['faults']['close'];
    return $GLOBALS['case'] !== 'close-false';
}
function proc_close($handle) {
    $GLOBALS['events'][] = 'reap';
    \fclose($handle);
    if (isset($GLOBALS['faults']['reap'])) throw $GLOBALS['faults']['reap'];
    return $GLOBALS['case'] === 'exit-failure' ? 1 : ($GLOBALS['case'] === 'exit-unknown' ? -1 : 0);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/userSliceMemsw.php', 'MemswWriteFixture');
        $script .= <<<'PHP'
$cases = ['success' => [], 'short' => [], 'zero' => [], 'write-false' => [],
    'close-false' => [], 'exit-failure' => [], 'exit-unknown' => [],
    'launch-false' => [], 'launch-throw' => ['launch'],
    'write-throw' => ['write'], 'close-throw' => ['close'], 'reap-throw' => ['reap'],
    'write-close-throw' => ['write', 'close'], 'write-reap-throw' => ['write', 'reap'],
    'close-reap-throw' => ['close', 'reap'], 'all-throw' => ['write', 'close', 'reap']];
$rows = [];
foreach (['RuntimeException', 'Error'] as $faultType) {
    foreach ($cases as $case => $stages) {
        $GLOBALS['case'] = $case; $GLOBALS['faults'] = []; $GLOBALS['events'] = [];
        $GLOBALS['pipe'] = null; $GLOBALS['process'] = null; $GLOBALS['input'] = null;
        foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $faultType($stage);
        $result = null; $caught = null;
        try { $result = pmssUserSliceMemswWrite('/fixture/memory limit', '-1'); }
        catch (\Throwable $error) { $caught = $error; }
        // The last failing cleanup stage owns throwable precedence.
        $expectedFault = $stages === [] ? null : $GLOBALS['faults'][end($stages)];
        $rows[] = [$case, $faultType, $result, $caught === $expectedFault,
            $GLOBALS['events'], $GLOBALS['launch'], $GLOBALS['input'],
            is_resource($GLOBALS['pipe']), is_resource($GLOBALS['process'])];
        foreach (['pipe', 'process'] as $key) {
            if (is_resource($GLOBALS[$key])) \fclose($GLOBALS[$key]);
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(32, count($rows));
        foreach ($rows as [$case, $faultType, $result, $sameFault, $events, $launch, $input, $pipeLive, $childLive]) {
            $label = $case.':'.$faultType;
            $launched = strpos($case, 'launch-') !== 0;
            $thrown = strpos($case, 'throw') !== false;
            $this->assertSame($thrown ? null : $case === 'success', $result, $label);
            $this->assertTrue($sameFault, $label);
            $this->assertSame($launched ? ['open', 'write', 'close', 'reap'] : ['open'], $events, $label);
            $this->assertSame(['timeout --kill-after=5s 30s tee '.escapeshellarg('/fixture/memory limit'),
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']]], $launch, $label);
            $this->assertSame($launched ? "-1\n" : null, $input, $label);
            $this->assertSame([false, false], [$pipeLive, $childLive], $label);
        }
    }
}
