<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';

/** Cleanup faults in scripts/lib/lighttpd/rootStage.php must still attempt directory removal. */
class RootStageDirectoryCleanupSafetyTest extends TestCase
{
    public function testDirectoryCleanupRunsAfterFileRemovalFailuresAndPreservesThrowables(): void
    {
        $script = <<<'PHP'
namespace RootStageDirectoryCleanupFixture;
function observe($phase, $result = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return $GLOBALS['case'] === $phase.'-false' ? false : $result;
}
// Intercept every filesystem/publication operation; only memory streams are real.
function pmssRootStageContext($path) { return ['/fixture', '/fixture/fixtur01', 'fixtur01']; }
function function_exists($name) { return true; }
function posix_geteuid() { return 0; }
function pmssPathTargetIsSafe(...$args) { return true; }
function pmssPathWithinResolvedRoot(...$args) { return true; }
function pmssRootStageSweep($root) {}
function random_bytes($length) { return str_repeat('a', $length); }
function lstat($path) {
    if (substr($path, -5) === '/file') return ['dev' => 7, 'ino' => 19, 'mode' => 0100640];
    $stat = ['dev' => 7, 'mode' => 0040700, 'uid' => 0];
    return strpos($path, '/.pmss-root-stage-') !== false ? observe('inspect', $stat) : $stat;
}
function mkdir($path, $mode) { $GLOBALS['directory'] = [$path, $mode]; return observe('mkdir'); }
function fopen($path, $mode) {
    $GLOBALS['open'] = [$path, $mode];
    return observe('open') ? ($GLOBALS['handle'] = \fopen('php://memory', 'w+')) : false;
}
function pmssStreamHandleIsOpen($handle) { return is_resource($handle); }
function pmssStreamWriteAll($handle, $content) { $GLOBALS['content'] = $content; return observe('write'); }
function fflush($handle) { return observe('flush'); }
function fclose($handle) { \fclose($handle); return observe('close'); }
function chown($path, $owner) { return observe('owner'); }
function chgrp($path, $group) { return observe('group'); }
function chmod($path, $mode) { return observe('mode'); }
function pmssUserFileStatIsSingleRegular($stat) { return true; }
function pmssRootStagePublishCommand(...$args) { return 'fixture-command'; }
function pmssRootStagePublishRun($command, &$failure) {
    $ok = observe('publish');
    $failure = $ok ? '' : 'stage=publish rc=23';
    return $ok;
}
function unlink($path) { $GLOBALS['removed'][] = $path; return observe('unlink'); }
function rmdir($path) { $GLOBALS['directories'][] = $path; return observe('rmdir'); }
PHP;
        $script .= \pmssTestingFunctionExport('pmssRootStageFileWrite', 'RootStageDirectoryCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
$cases = ['success', 'mkdir-false', 'inspect-false', 'open-false', 'write-false', 'flush-false',
    'group-false', 'publish-false', 'unlink-false', 'rmdir-false', 'write', 'close', 'publish',
    'unlink', 'rmdir', 'write+unlink', 'close+unlink', 'publish+unlink',
    'unlink+rmdir', 'write+unlink+rmdir'];
foreach ($cases as $case) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case;
        $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['removed'] = $GLOBALS['directories'] = [];
        $GLOBALS['handle'] = $GLOBALS['open'] = $GLOBALS['content'] = null;
        if ($case !== 'success' && strpos($case, '-false') === false) {
            foreach (explode('+', $case) as $phase) $GLOBALS['faults'][$phase] = new $kind($phase);
        }
        $result = $caught = null; $failure = 'stale';
        try { $result = pmssRootStageFileWrite('/fixture/fixtur01/target', 'fixture bytes', 'root', 0640, false, $failure); }
        catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$case, $result, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            is_resource($GLOBALS['handle']), $failure, $GLOBALS['events'], $GLOBALS['removed'],
            $GLOBALS['directories'], $GLOBALS['directory'], $GLOBALS['open'], $GLOBALS['content']];
        // Bound fixture resources even when testing a broken implementation.
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(40, count($rows));
        $stage = '/fixture/.pmss-root-stage-'.str_repeat('61', 12);
        $normal = ['mkdir', 'inspect', 'open', 'write', 'flush', 'close', 'owner', 'group', 'mode', 'publish'];
        foreach ($rows as $row) {
            [$case] = $row;
            $ordinary = $case === 'success' || strpos($case, '-false') !== false;
            $phase = explode('+', str_replace('-false', '', $case))[0];
            $trace = $normal;
            if (in_array($phase, $normal, true)) {
                $trace = array_slice($normal, 0, array_search($phase, $normal, true) + 1);
                if (in_array($phase, ['write', 'flush'], true)) $trace[] = 'close';
            }
            if ($case !== 'mkdir-false') array_push($trace, 'unlink', 'rmdir');
            $opened = !in_array($case, ['mkdir-false', 'inspect-false'], true);
            $expectedStage = ['mkdir' => 'mkdir', 'inspect' => 'stage-check', 'open' => 'open',
                'write' => 'write', 'flush' => 'write', 'close' => 'write', 'group' => 'group',
                'publish' => 'immutable-clear'][$phase] ?? null;
            $expectedFailure = $case === 'publish-false' ? 'stage=publish rc=23'
                : ($expectedStage === null ? '' : 'stage='.$expectedStage.' rc=not-started');
            $this->assertSame([
                $ordinary ? !in_array($case, ['mkdir-false', 'inspect-false', 'open-false',
                    'write-false', 'flush-false', 'group-false', 'publish-false'], true) : null,
                true, false, $expectedFailure, $trace,
                $case === 'mkdir-false' ? [] : [$stage.'/file'], $case === 'mkdir-false' ? [] : [$stage],
                [$stage, 0700], $opened ? [$stage.'/file', 'x+b'] : null,
                $opened && $case !== 'open-false' ? 'fixture bytes' : null,
            ], array_slice($row, 1), $case);
        }
    }
}
