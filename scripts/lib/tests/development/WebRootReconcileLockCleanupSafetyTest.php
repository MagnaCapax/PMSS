<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRootReconcile.php';

/** Lock cleanup in scripts/lib/update/users/webRootReconcile.php; testing machines only. */
class WebRootReconcileLockCleanupSafetyTest extends TestCase
{
    public function testSummaryFaultsCannotSkipLockRelease(): void
    {
        // Export the real controller and formatter; all system boundaries are memory-only stubs.
        $script = <<<'PHP'
namespace WebRootLockCleanupFixture;
function pmssUserContextIdentity($ctx) { return ['fixtur01', '/fixture/homes/fixtur01']; }
function pmssResolvePathFromEnv($key, $default) { return $default; }
function pmssRuntimeLockDir() { return '/fixture/locks'; }
function pmssPathTargetIsSafe($path, $directory) { return true; }
function pmssPathWithinResolvedRoot($path, $root) { return true; }
function pmssPathAbsoluteStringIsSafe($path, $options) { return true; }
function pmssPathSegmentsAreSafe($path, $missing, $links) { return $GLOBALS['mode'] !== 'unsafe'; }
function pmssSkeletonBase() { return '/fixture/skel'; }
function is_dir($path) { return true; }
function is_link($path) { return false; }
function file_exists($path) { return true; }
function pmssDirectoryEntriesRead($path) { return ['index.php']; }
function pmssLockFileAcquire($path, $nonBlocking) {
    $GLOBALS['events'][] = 'acquire';
    $GLOBALS['arguments'] = [$path, $nonBlocking];
    if ($GLOBALS['mode'] === 'acquire') throw $GLOBALS['bodyFailure'];
    if ($GLOBALS['mode'] === 'busy') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function pmssUserWebRootReconcileCopyEntry($source, $target, $home, $relative, &$stats, $user, $logger, $merge) {
    $GLOBALS['events'][] = 'copy';
    if ($GLOBALS['mode'] === 'body') throw $GLOBALS['bodyFailure'];
    return $GLOBALS['mode'] !== 'refused';
}
function pmssUserEnsureWebRootSymlinks($ctx) { $GLOBALS['events'][] = 'symlinks'; }
function pmssUserMigrateWebRootState($ctx, $logger) { $GLOBALS['events'][] = 'migrate'; }
function pmssUserWebRootMigrationLog($user, $logger, $message) {
    $summary = strpos($message, 'web_root_reconcile ') === 0;
    $GLOBALS['events'][] = $summary ? 'summary' : 'skip';
    if ($summary) $GLOBALS['summaries'][] = [$message, is_resource($GLOBALS['handle'])];
    $logger('[user:'.$user.'] '.$message);
}
function pmssLockHandleRelease($handle) {
    $GLOBALS['events'][] = 'release';
    $GLOBALS['releasedOwnedHandle'] = $handle === $GLOBALS['handle'];
    \fclose($handle);
    if ($GLOBALS['releaseFault']) throw $GLOBALS['releaseFailure'];
}
PHP;
        foreach (['pmssUserReconcileWebRoot', 'pmssUserWebRootReconcileLogSummary'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'WebRootLockCleanupFixture');
        }
        $script .= <<<'PHP'
$results = [];
$cases = ['success' => ['ok', false, false], 'unsafe' => ['unsafe', false, false],
    'busy' => ['busy', false, false], 'acquire' => ['acquire', false, false],
    'refused' => ['refused', false, false], 'body' => ['body', false, false],
    'summary' => ['ok', true, false], 'refused-summary' => ['refused', true, false],
    'unsafe-summary' => ['unsafe', true, false], 'body-summary' => ['body', true, false],
    'release' => ['ok', false, true], 'body-release' => ['body', false, true],
    'summary-release' => ['ok', true, true], 'body-summary-release' => ['body', true, true]];
foreach ($cases as $case => [$mode, $summaryFault, $releaseFault]) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['mode'] = $mode; $GLOBALS['releaseFault'] = $releaseFault;
        $GLOBALS['events'] = $GLOBALS['arguments'] = $GLOBALS['summaries'] = [];
        $GLOBALS['handle'] = null; $GLOBALS['releasedOwnedHandle'] = false;
        $GLOBALS['bodyFailure'] = new $kind('body fault');
        $GLOBALS['summaryFailure'] = new $kind('summary fault');
        $GLOBALS['releaseFailure'] = new $kind('release fault');
        $logger = static function ($message) use ($summaryFault) {
            if ($summaryFault && strpos($message, 'web_root_reconcile ') !== false) throw $GLOBALS['summaryFailure'];
        };
        $result = $caught = null;
        try { $result = pmssUserReconcileWebRoot([], $logger); }
        catch (\Throwable $error) { $caught = $error; }
        $failure = null;
        foreach (['body', 'summary', 'release'] as $step) {
            if ($caught === $GLOBALS[$step.'Failure']) $failure = $step;
        }
        // Observe leaks before fixture cleanup so a failing implementation cannot hide them.
        $live = is_resource($GLOBALS['handle']);
        if ($live) \fclose($GLOBALS['handle']);
        $results[] = [$case, $mode, $kind, $result, $failure, $live, $GLOBALS['events'],
            $GLOBALS['arguments'], $GLOBALS['summaries'], $GLOBALS['releasedOwnedHandle']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(28, count($results));
        foreach ($results as [$case, $mode, $kind, $result, $failure, $live, $events, $arguments, $summaries, $released]) {
            $label = $case.'/'.$kind;
            $acquired = !in_array($mode, ['busy', 'acquire'], true);
            $expected = ['acquire'];
            if ($mode === 'busy') $expected[] = 'skip';
            if ($acquired) {
                if ($mode !== 'unsafe') $expected[] = 'copy';
                if ($mode === 'ok') { $expected[] = 'symlinks'; $expected[] = 'migrate'; }
                $expected[] = 'summary'; $expected[] = 'release';
            }
            $expectedFailure = strpos($case, 'release') !== false ? 'release'
                : (strpos($case, 'summary') !== false ? 'summary'
                    : (in_array($mode, ['acquire', 'body'], true) ? 'body' : null));
            $expectedResult = $expectedFailure === null ? $mode === 'ok' : null;
            $this->assertSame([$expectedResult, $expectedFailure, false, $expected, $acquired],
                [$result, $failure, $live, $events, $released], $label);
            $this->assertSame(['/fixture/locks/pmss-user-web-root-fixtur01.lock', true], $arguments, $label);
            $this->assertSame($acquired ? 1 : 0, count($summaries), $label);
            if ($acquired) {
                $reason = $mode === 'unsafe' ? 'unsafe-reconcile-source'
                    : ($mode === 'refused' ? 'restore-failed' : 'managed-entry-check');
                $summaryMode = $mode === 'unsafe' ? 'unknown' : 'partial-merge';
                $this->assertTrue($summaries[0][1], $label.' summary must precede release');
                $this->assertMatches('/^web_root_reconcile reason='.$reason.' mode='.$summaryMode
                    .' files_restored=0 duration_ms=[0-9]+ preserved_conflict=0$/', $summaries[0][0], $label);
            }
        }
    }
}
