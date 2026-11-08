<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Verify scripts/lib/user/watchdogProcess.php lock ownership using memory streams only. */
class WatchdogLockSafetyTest extends TestCase
{
    public function testLockOwnershipTransfersOnlyAfterDescriptorExport(): void
    {
        $script = <<<'PHP'
namespace WatchdogLockFixture;
function pmssLockFileAcquire($path, $nonBlocking) {
    $GLOBALS['events'][] = 'acquire';
    $GLOBALS['arguments'] = [$path, $nonBlocking];
    if (strpos($GLOBALS['case'], 'acquire-') === 0) throw $GLOBALS['failure'];
    if ($GLOBALS['case'] === 'busy') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function pmssLockHandleExportChildCloseFds($handle) {
    $GLOBALS['events'][] = 'export';
    $GLOBALS['exports'][] = is_resource($handle) && $handle === $GLOBALS['handle'];
    if (strpos($GLOBALS['case'], 'export-') === 0) throw $GLOBALS['failure'];
}
function fclose($handle) {
    $GLOBALS['events'][] = 'close';
    return \fclose($handle);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/watchdogProcess.php', 'WatchdogLockFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['success' => null, 'busy' => null,
    'acquire-exception' => new \RuntimeException('acquire'), 'acquire-error' => new \Error('acquire'),
    'export-exception' => new \RuntimeException('export'), 'export-error' => new \Error('export')];
foreach ($cases as $case => $failure) {
    $GLOBALS['case'] = $case; $GLOBALS['failure'] = $failure;
    $GLOBALS['events'] = []; $GLOBALS['arguments'] = []; $GLOBALS['exports'] = [];
    $GLOBALS['handle'] = null;
    $result = null; $caught = null;
    try { $result = pmssUserWatchdogLockAcquire('/fixture/watchdog.lock'); }
    catch (\Throwable $error) { $caught = $error; }
    // Check ownership before fixture cleanup can hide a leak or premature close.
    $live = is_resource($GLOBALS['handle']);
    $results[] = [$case, $result === false, is_resource($result) && $result === $GLOBALS['handle'],
        $live, $caught === $failure, $GLOBALS['events'], $GLOBALS['arguments'], $GLOBALS['exports']];
    if ($live) \fclose($GLOBALS['handle']);
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $expectedEvents = [
            'success' => ['acquire', 'export'], 'busy' => ['acquire'],
            'acquire-exception' => ['acquire'], 'acquire-error' => ['acquire'],
            'export-exception' => ['acquire', 'export', 'close'],
            'export-error' => ['acquire', 'export', 'close'],
        ];
        $this->assertSame(count($expectedEvents), count($results));
        foreach ($results as [$case, $failed, $transferred, $live, $sameFailure, $events, $arguments, $exports]) {
            $success = $case === 'success';
            $this->assertSame([$case === 'busy', $success, $success, true],
                [$failed, $transferred, $live, $sameFailure], $case);
            $this->assertSame($expectedEvents[$case], $events, $case);
            $this->assertSame(['/fixture/watchdog.lock', true], $arguments, $case);
            $this->assertSame(in_array('export', $events, true) ? [true] : [], $exports, $case);
        }
    }
}
