<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Isolate metadata inspection and mutation; no account files or permissions change. */
class UserPermissionsStreamSafetyTest extends TestCase
{
    public function testOpenedMetadataRepairsCloseStreamsAcrossEveryInspectionBoundary(): void
    {
        $script = <<<'PHP'
namespace PermissionsStreamFixture;
// Only php://temp is opened; all filesystem observations and mutations are stubs.
function pmssUserFileOpenVerified($path, $mode) {
    $GLOBALS['calls'][] = ['open', $path, $mode];
    return $GLOBALS['handle'] = $GLOBALS['case'] === 'missing' ? null : \fopen('php://temp', 'w+');
}
function inspect($phase, $result) {
    $GLOBALS['calls'][] = [$phase];
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $result;
}
function pmssStreamHandleIsOpen($handle) { return is_resource($handle); }
function fstat($handle) { return inspect('stat', ['uid' => $GLOBALS['case'] === 'owner' ? 8 : 7]); }
function realpath($path) { return inspect('realpath', '/home/fixtur01'); }
function pmssUserFileHandleProcPath($handle) { return inspect('descriptor', '/proc/self/fd/123'); }
function pmssUserFileStatIsSingleRegular($stat) { return $GLOBALS['case'] !== 'invalid'; }
function readlink($path) {
    return inspect('readlink', $GLOBALS['case'] === 'outside' ? '/outside/state' : '/home/fixtur01/state');
}
function chmod($path, $mode) { return inspect('chmod', $GLOBALS['case'] !== 'failed'); }
function chgrp($path, $group) { return inspect('chgrp', $GLOBALS['case'] !== 'failed'); }
function pmssUserLog($user, $message) { $GLOBALS['calls'][] = ['log']; }
function pmssUserPermissionsFailed($command) { $GLOBALS['calls'][] = ['failed']; }
function fclose($handle) { $GLOBALS['calls'][] = ['close']; return \fclose($handle); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/permissionsApply.php', 'PermissionsStreamFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['mode', 'group'] as $repair) {
    $action = $repair === 'mode' ? 'chmod' : 'chgrp';
    $run = static function () use ($repair) {
        return $repair === 'mode'
            ? pmssUserPermissionsOpenedModeSet('fixtur01', '/home/fixtur01', '/fixture/state', 0640, 7)
            : pmssUserPermissionsOpenedGroupSet('fixtur01', '/home/fixtur01', '/fixture/state', 'fixture-group', 7);
    };
    // Both exception families must retain identity and stop before subsequent operations.
    foreach (['stat', 'realpath', 'descriptor', 'readlink', $action] as $phase) {
        foreach ([new \RuntimeException($phase), new \Error($phase)] as $failure) {
            $GLOBALS['case'] = 'success'; $GLOBALS['calls'] = []; $caught = null;
            try { $run(); } catch (\Throwable $error) { $caught = $error; }
            $closed = !is_resource($GLOBALS['handle']);
            if (!$closed) \fclose($GLOBALS['handle']); // Clean a leak only after observing it.
            $results[] = [$repair, $phase, $caught === $failure, $closed, $GLOBALS['calls']];
        }
    }
    $GLOBALS['phase'] = null;
    foreach (['missing', 'invalid', 'owner', 'outside', 'failed', 'success'] as $case) {
        $GLOBALS['case'] = $case; $GLOBALS['calls'] = [];
        $result = $run();
        $results[] = [$repair, $case, $result, !is_resource($GLOBALS['handle']), $GLOBALS['calls']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $index = 0;
        foreach (['mode', 'group'] as $repair) {
            $action = $repair === 'mode' ? 'chmod' : 'chgrp';
            $open = [['open', '/fixture/state', 'rbn']];
            $checks = [['stat'], ['realpath'], ['descriptor'], ['readlink'], [$action]];
            foreach (array_column($checks, 0) as $position => $phase) {
                for ($kind = 0; $kind < 2; $kind++) {
                    $trace = array_merge($open, array_slice($checks, 0, $position + 1), [['close']]);
                    $this->assertSame([$repair, $phase, true, true, $trace], $results[$index++]);
                }
            }
            foreach (['missing', 'invalid', 'owner', 'outside', 'failed', 'success'] as $case) {
                $trace = $open;
                if ($case !== 'missing') {
                    $trace = array_merge($trace, array_slice($checks, 0,
                        in_array($case, ['invalid', 'owner'], true) ? 3 : ($case === 'outside' ? 4 : 5)));
                }
                if (in_array($case, ['missing', 'invalid', 'owner', 'outside'], true)) $trace[] = ['log'];
                if ($case === 'failed') $trace[] = ['failed'];
                if ($case !== 'missing') $trace[] = ['close'];
                $this->assertSame([$repair, $case, $case === 'success', true, $trace], $results[$index++]);
            }
        }
        $this->assertSame($index, count($results));
    }
}
