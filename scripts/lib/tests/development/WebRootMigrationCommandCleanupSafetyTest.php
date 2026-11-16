<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRoot.php';

/** Owned-child cleanup for scripts/lib/update/users/webRoot.php; testing machines only. */
class WebRootMigrationCommandCleanupSafetyTest extends TestCase
{
    public function testFallbackReapsOwnedChildrenAfterFaultsAndRetainsItsGuards(): void
    {
        $script = <<<'PHP'
namespace WebRootMigrationCommandCleanupFixture;
function observe($phase, $value = null) {
    $GLOBALS['events'][] = $phase;
    $GLOBALS['counts'][$phase] = ($GLOBALS['counts'][$phase] ?? 0) + 1;
    if (isset($GLOBALS['faults'][$phase]) && $GLOBALS['counts'][$phase] === 1) throw $GLOBALS['faults'][$phase];
    return $value;
}
// These probes never consult real accounts, homes or processes.
function function_exists($name) { return $name !== 'proc_terminate' || $GLOBALS['case'] !== 'reap+terminate-unavailable'; }
function posix_geteuid() { return $GLOBALS['case'] === 'root-refused' ? 0 : 2001; }
function posix_getpwnam($user) { return ['uid' => posix_geteuid()]; }
function pmssUserWebRootMigrationRootParentsSafe($home, $paths) { return false; }
function pmssAccountPathRun($user, $home, $paths, $command, $input) { return observe('account', $GLOBALS['case'] === 'account-success'); }
function fileowner($home) { return $GLOBALS['case'] === 'owner-refused' ? 2002 : 2001; }
function pmssPathTargetIsSafe($home, $directory) { return $GLOBALS['case'] !== 'home-refused'; }
function pmssPathSegmentsAreSafe($path, $missing, $symlinks) { return $GLOBALS['case'] !== 'segments-refused'; }
function pmssPathWithinResolvedRoot($path, $home) { return $GLOBALS['case'] !== 'containment-refused'; }
function proc_open($command, $descriptors, &$pipes) {
    observe('launch');
    $GLOBALS['launch'] = [$command, $descriptors];
    if ($GLOBALS['case'] === 'launch-false') return false;
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function proc_terminate($process) {
    if ($process !== $GLOBALS['process']) throw new \LogicException('Unowned process');
    return observe('terminate', $GLOBALS['case'] !== 'reap+terminate-false');
}
function proc_close($process) {
    if ($process !== $GLOBALS['process']) throw new \LogicException('Unowned process');
    observe('reap');
    if ($GLOBALS['counts']['reap'] === 2 && isset($GLOBALS['faults']['reap-retry'])) throw $GLOBALS['faults']['reap-retry'];
    \fclose($process);
    observe('reaped');
    return $GLOBALS['case'] === 'child-false' ? 23 : 0;
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserWebRootMigrationRun', 'WebRootMigrationCommandCleanupFixture');
        $script .= <<<'PHP'
$results = [];
$ordinary = ['success', 'child-false', 'launch-false', 'account-success', 'root-refused',
    'input-refused', 'owner-refused', 'home-refused', 'segments-refused', 'containment-refused'];
$cases = array_merge($ordinary, ['account', 'launch', 'reap', 'reaped', 'reap+terminate',
    'reap+terminate-false', 'reap+terminate-unavailable', 'reap+reaped', 'reap+reap-retry',
    'reap+terminate+reap-retry']);
foreach ($cases as $case) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = $GLOBALS['counts'] = $GLOBALS['faults'] = [];
        $GLOBALS['process'] = $GLOBALS['launch'] = null;
        // Distinct faults prove both cleanup ordering and throwable precedence.
        foreach (explode('+', $case) as $phase) {
            if (!in_array($case, $ordinary, true) && !in_array($phase, ['terminate-false', 'terminate-unavailable'], true))
                $GLOBALS['faults'][$phase] = new $kind($phase);
        }
        $ok = $caught = null;
        try {
            $ok = pmssUserWebRootMigrationRun('fixtur01', '/fixture/home', ['/fixture/home/data'],
                'fixture-command', $case === 'input-refused' ? 'fixture-input' : null);
        } catch (\Throwable $error) { $caught = $error; }
        $results[] = [$case, $ok, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            is_resource($GLOBALS['process']), $GLOBALS['events'], $GLOBALS['launch']];
        // Inspect ownership before releasing any leaked fixture stream.
        if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(40, count($results));
        foreach ($results as $row) {
            [$case, , , , , $launch] = $row;
            $fault = in_array($case, ['account', 'launch', 'reap', 'reaped'], true) || strpos($case, 'reap+') === 0;
            $expected = $case === 'root-refused' ? [] : ['account'];
            $launched = in_array($case, ['success', 'child-false', 'launch-false', 'launch', 'reap', 'reaped'], true)
                || strpos($case, 'reap+') === 0;
            if ($launched) $expected[] = 'launch';
            if ($launched && !in_array($case, ['launch', 'launch-false'], true)) {
                $expected[] = 'reap';
                if ($case === 'reap' || strpos($case, 'reap+') === 0) {
                    if ($case !== 'reap+terminate-unavailable') $expected[] = 'terminate';
                    $expected[] = 'reap';
                }
                if (strpos($case, 'reap-retry') === false) $expected[] = 'reaped';
            }
            $this->pmssAssertArraySubsetSame([
                1 => $fault ? null : in_array($case, ['success', 'account-success'], true),
                2 => true, 3 => strpos($case, 'reap-retry') !== false, 4 => $expected,
            ], $row, $case.':');
            if ($launch !== null) $this->assertSame(["sh -c 'fixture-command'", [
                0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
            ]], $launch, $case);
        }
    }
}
