<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Diagnostic faults must not retain private stderr descriptors or launch real children. */
class StderrCaptureSafetyTest extends TestCase
{
    public function testBothCommandHelpersCloseCaptureOnDiagnosticFailures(): void
    {
        $script = <<<'PHP'
namespace StderrCaptureFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $value;
}
// Account validation stays hermetic, including on hosts without POSIX functions.
function function_exists($name) { return in_array($name, ['posix_getpwnam', 'posix_geteuid'], true) || \function_exists($name); }
function posix_getpwnam($name) { return ['uid' => 12345]; }
function posix_geteuid() { return 12345; }
function fileowner($path) { return 12345; }
function is_dir($path) { return true; }
function pmssPathTargetIsSafe(...$args) { return true; }
function pmssPathSegmentsAreSafe(...$args) { return true; }
function pmssPathWithinResolvedRoot(...$args) { return true; }
function tmpfile() {
    return $GLOBALS['capture'] = $GLOBALS['case'] === 'unavailable' ? false : \fopen('php://memory', 'w+');
}
function stream_get_meta_data($handle) { return ['uri' => '/fixture/stderr']; }
function unlink($path) { return $GLOBALS['case'] !== 'unlink-failed'; }
function proc_open($command, $descriptor, &$pipes, $cwd = null) {
    observe('launch', null);
    if ($GLOBALS['case'] === 'launch-failed') return false;
    // The root publisher has no stdin pipe; the account runner owns one.
    $pipes = $descriptor[0][0] === 'pipe' ? [0 => \fopen('php://memory', 'w+')] : [];
    return \fopen('php://memory', 'w+');
}
function proc_close($process) { \fclose($process); return $GLOBALS['case'] === 'success' ? 0 : 23; }
function rewind($handle) { return observe('rewind', $GLOBALS['case'] !== 'rewind-failed'); }
function fread($handle, $length) {
    $GLOBALS['readLength'] = $length;
    return observe('read', $GLOBALS['case'] === 'read-failed' ? false : ' diagnostic ');
}
function pmssLogControlCharactersReplace($value) { return observe('sanitize', $value); }
function fclose($handle) {
    if ($handle === $GLOBALS['capture']) $GLOBALS['events'][] = 'close';
    return \fclose($handle);
}
PHP;
        foreach (['accountPath.php', 'rootStagePublish.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/'.$module, 'StderrCaptureFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (['account', 'publish'] as $helper) {
    foreach (['success', 'child-failed', 'unavailable', 'unlink-failed', 'launch-failed', 'rewind-failed', 'read-failed',
        'launch', 'rewind', 'read', 'sanitize'] as $case) {
        $phase = in_array($case, ['launch', 'rewind', 'read', 'sanitize'], true) ? $case : null;
        foreach ($phase === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
            $GLOBALS['case'] = $case; $GLOBALS['phase'] = $phase; $GLOBALS['failure'] = $failure;
            $GLOBALS['events'] = []; $GLOBALS['readLength'] = null; $GLOBALS['capture'] = null;
            $caught = null; $ok = null; $reason = 'stale'; $timedOut = true;
            try {
                $ok = $helper === 'publish' ? pmssRootStagePublishRun('fixture-command', $reason)
                    : pmssAccountPathRun('fixtur01', '/fixture/home', ['/fixture/home/target'],
                        'fixture-command', null, 0, $timedOut, $reason);
            } catch (\Throwable $error) { $caught = $error; }
            $open = is_resource($GLOBALS['capture']);
            if ($open) \fclose($GLOBALS['capture']);
            $results[] = [$helper, $case, $ok, $caught === $failure, $open, $reason,
                $GLOBALS['events'], $GLOBALS['readLength'], $helper === 'account' ? $timedOut : false];
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $index = 0;
        foreach (['account', 'publish'] as $helper) {
            $stderrStage = $helper === 'account' ? 'stderr' : 'publish-stderr';
            $launchStage = $helper === 'account' ? 'launch' : 'publish-launch';
            $childStage = $helper === 'account' ? 'child' : 'publish';
            $diagnostics = ['rewind', 'read', 'sanitize', 'close'];
            $trace = array_merge(['launch'], $diagnostics);
            $cases = [
                'success' => [true, '', $trace, 300],
                'child-failed' => [false, 'stage='.$childStage.' rc=23 stderr=diagnostic', $trace, 300],
                'unavailable' => [false, 'stage='.$stderrStage.' rc=not-started', [], null],
                'unlink-failed' => [false, 'stage='.$stderrStage.' rc=not-started stderr=diagnostic', $diagnostics, 300],
                'launch-failed' => [false, 'stage='.$launchStage.' rc=not-started stderr=diagnostic', $trace, 300],
                'rewind-failed' => [false, 'stage='.$childStage.' rc=23', ['launch', 'rewind', 'close'], null],
                'read-failed' => [false, 'stage='.$childStage.' rc=23', $trace, 300],
            ];
            foreach (['launch', 'rewind', 'read', 'sanitize'] as $phase) {
                $events = $phase === 'launch' ? $trace
                    : array_merge(array_slice($trace, 0, array_search($phase, $trace, true) + 1), ['close']);
                $reason = $phase === 'launch' ? 'stage='.$launchStage.' rc=not-started stderr=diagnostic'
                    : 'stage='.$childStage.' rc=23';
                $cases[$phase] = [null, $reason, $events, $phase === 'rewind' ? null : 300];
            }
            foreach ($cases as $case => [$ok, $reason, $events, $length]) {
                for ($kind = 0; $kind < ($ok === null ? 2 : 1); $kind++) {
                    $this->assertSame([$helper, $case, $ok, true, false, $reason, $events, $length, false],
                        $results[$index++], $helper.':'.$case);
                }
            }
        }
        $this->assertSame($index, count($results));
    }
}
