<?php
/** Verify owned-child recovery after reap faults in scripts/lib/cgroup/userSliceMemsw.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserSliceMemswProcessCleanupSafetyTest extends TestCase
{
    public function testReapFaultsRetainOwnershipCleanupAndThrowablePrecedence(): void
    {
        $script = <<<'PHP'
namespace MemswProcessCleanupFixture;
// In-memory handles replace the pipe and child; no process or sysfs operation runs.
function observe($stage) {
    $GLOBALS['events'][] = $stage;
    if (isset($GLOBALS['faults'][$stage])) throw $GLOBALS['faults'][$stage];
}
function function_exists($name) { return $name !== 'proc_terminate' || $GLOBALS['case'] !== 'unavailable'; }
function proc_open($command, $descriptor, &$pipes) {
    observe('open');
    $pipes = [$GLOBALS['pipe'] = \fopen('php://memory', 'w+')];
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $input) { observe('write'); return strlen($input); }
function fclose($handle) {
    \fclose($handle);
    observe('close');
    return true;
}
function proc_terminate($handle) {
    if ($handle !== $GLOBALS['process']) throw new \LogicException('Unowned child');
    observe('terminate');
    return $GLOBALS['case'] !== 'terminate-false';
}
function proc_close($handle) {
    if ($handle !== $GLOBALS['process']) throw new \LogicException('Unowned child');
    $stage = ++$GLOBALS['reaps'] === 1 ? 'reap' : 'retry';
    // Model both a failure before reaping and a fault after the child is already gone.
    if ($GLOBALS['case'] === 'already-reaped' || $stage === 'retry' || $GLOBALS['case'] === 'success') {
        \fclose($handle);
    }
    observe($stage);
    return 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandProcess.php', 'MemswProcessCleanupFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/userSliceMemsw.php', 'MemswProcessCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
$cases = ['success' => [], 'reap' => ['reap'], 'already-reaped' => ['reap'],
    'write-reap' => ['write', 'reap'], 'close-reap' => ['close', 'reap'],
    'all' => ['write', 'close', 'reap'], 'terminate' => ['reap', 'terminate'],
    'retry' => ['reap', 'retry'], 'combined' => ['reap', 'terminate', 'retry'],
    'terminate-false' => ['reap'], 'unavailable' => ['reap']];
foreach ([\RuntimeException::class, \Error::class] as $kind) {
    foreach ($cases as $case => $stages) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = $GLOBALS['faults'] = [];
        $GLOBALS['reaps'] = 0; $GLOBALS['pipe'] = $GLOBALS['process'] = null;
        foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage);
        $result = $caught = null;
        try { $result = pmssUserSliceMemswWrite('/fixture/memory limit', '-1'); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFault = $stages === [] ? null : $GLOBALS['faults'][end($stages)];
        $rows[] = [$case, $result, $caught === $expectedFault, $GLOBALS['events'],
            is_resource($GLOBALS['pipe']), is_resource($GLOBALS['process'])];
        // Inspect ownership first, then release any leaked fixture handles.
        foreach (['pipe', 'process'] as $key) {
            if (is_resource($GLOBALS[$key])) \fclose($GLOBALS[$key]);
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(22, count($rows));
        foreach ($rows as [$case, $result, $sameFault, $events, $pipeLive, $childLive]) {
            $expected = ['open', 'write', 'close', 'reap'];
            if (!in_array($case, ['success', 'already-reaped'], true)) {
                if ($case !== 'unavailable') $expected[] = 'terminate';
                $expected[] = 'retry';
            }
            $this->assertSame($case === 'success' ? true : null, $result, $case);
            $this->assertTrue($sameFault, $case);
            $this->assertSame($expected, $events, $case);
            $this->assertSame([false, false], [$pipeLive, $childLive], $case);
        }
    }
}
