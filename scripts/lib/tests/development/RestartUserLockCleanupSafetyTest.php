<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Memory-only acquisition faults for scripts/lib/user/restart.php. */
class RestartUserLockCleanupSafetyTest extends TestCase
{
    public function testFirstLockClosesWhenSecondLockAcquisitionThrows(): void
    {
        // Keep ordinary refusals and ignored close returns alongside thrown boundary faults.
        $first = 'path-restart acquire-restart';
        $both = $first.' path-recreate acquire-recreate';
        $cases = [
            ['success', null, false, [], 'prior', $both, 'success'],
            ['restart-busy', 'restart', true, [], 'restart already running', $first, null],
            ['restart-unavailable', 'restart', false, [], 'restart lock unavailable', $first, null],
            ['recreate-busy', 'recreate', true, [], 'rebuild in progress', $both.' close', null],
            ['recreate-unavailable', 'recreate', false, [], 'rebuild lock unavailable', $both.' close', null],
            ['close-false', 'recreate', false, [], 'rebuild lock unavailable', $both.' close', null],
            ['path-restart', null, false, ['path-restart'], 'prior', 'path-restart', 'not-returned'],
            ['acquire-restart', null, false, ['acquire-restart'], 'prior', $first, 'not-returned'],
            ['path-recreate', null, false, ['path-recreate'], 'prior', $first.' path-recreate close', 'not-returned'],
            ['acquire-recreate', null, false, ['acquire-recreate'], 'prior', $both.' close', 'not-returned'],
            ['close', 'recreate', false, ['close'], 'prior', $both.' close', 'not-returned'],
            ['path-close', null, false, ['path-recreate', 'close'], 'prior', $first.' path-recreate close', 'not-returned'],
            ['acquire-close', null, false, ['acquire-recreate', 'close'], 'prior', $both.' close', 'not-returned'],
        ];
        $script = <<<'PHP'
namespace RestartUserLockCleanupFixture;
function observe($step) {
    $GLOBALS['events'][] = $step;
    if (isset($GLOBALS['faults'][$step])) throw $GLOBALS['faults'][$step];
}
function pmssRuntimeLockPath($name) {
    observe(strpos($name, 'pmss-userRestart-') === 0 ? 'path-restart' : 'path-recreate');
    return '/fixture/locks/'.$name;
}
function pmssLockFileAcquire($path, $nonBlocking, $mode, $parents, $closeOnBusy, &$busy) {
    $which = strpos($path, 'pmss-userRestart-') !== false ? 'restart' : 'recreate';
    $GLOBALS['arguments'][] = [$path, $nonBlocking, $mode, $parents, $closeOnBusy, $busy];
    observe('acquire-'.$which);
    $busy = $GLOBALS['falseAt'] === $which && $GLOBALS['busy'];
    if ($GLOBALS['falseAt'] === $which) return false;
    return $GLOBALS['handles'][$which] = \fopen('php://memory', 'w+');
}
function fclose($handle) {
    $GLOBALS['closedOwned'] = $handle === ($GLOBALS['handles']['restart'] ?? null) && is_resource($handle);
    \fclose($handle);
    observe('close');
    return $GLOBALS['case'] !== 'close-false';
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/restart.php', 'RestartUserLockCleanupFixture');
        $script .= '$cases = '.var_export($cases, true).';';
        $script .= <<<'PHP'
$rows = [];
foreach ($cases as [$case, $falseAt, $busy, $stages]) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['falseAt'] = $falseAt; $GLOBALS['busy'] = $busy;
        $GLOBALS['events'] = $GLOBALS['arguments'] = $GLOBALS['handles'] = $GLOBALS['faults'] = [];
        $GLOBALS['closedOwned'] = false;
        foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage.' fault');
        $reason = 'prior'; $result = 'not-returned'; $caught = null;
        try { $result = pmssRestartUserLocksAcquire('fixtur01', $reason); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = $stages === [] ? null : $GLOBALS['faults'][end($stages)];
        // Record ownership before fixture teardown can hide leaked or prematurely closed streams.
        $live = array_map('is_resource', $GLOBALS['handles']);
        $transferred = is_array($result)
            && $result === ['recreate' => $GLOBALS['handles']['recreate'], 'restart' => $GLOBALS['handles']['restart']];
        $rows[] = [$case, $reason, is_array($result) ? 'success' : $result, $caught === $expectedFailure,
            $live, $transferred, $GLOBALS['closedOwned'], $GLOBALS['events'], $GLOBALS['arguments']];
        foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(count($cases) * 2, count($rows));
        foreach ($rows as $index => [$case, $reason, $result, $sameFailure, $live, $transferred, $closedOwned, $events, $arguments]) {
            [, , , , $expectedReason, $trace, $expectedResult] = $cases[intdiv($index, 2)];
            $this->assertSame([$expectedReason, $expectedResult, true, explode(' ', $trace)],
                [$reason, $result, $sameFailure, $events], $case);
            $success = $case === 'success';
            $closed = in_array('close', $events, true);
            $this->assertSame($success ? ['restart' => true, 'recreate' => true] : ($closed ? ['restart' => false] : []), $live, $case);
            $this->assertSame([$success, $closed], [$transferred, $closedOwned], $case);
            $expectedArguments = [];
            foreach (['restart' => 'Restart', 'recreate' => 'Recreate'] as $which => $label) {
                if (in_array('acquire-'.$which, $events, true)) {
                    $expectedArguments[] = ['/fixture/locks/pmss-user'.$label.'-fixtur01.lock', true, 'c', false, true, false];
                }
            }
            $this->assertSame($expectedArguments, $arguments, $case);
        }
    }
}
