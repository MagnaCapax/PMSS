<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';

/** Immutable restoration in scripts/lib/lighttpd/rootStage.php survives publication faults. */
class RootStageImmutableCleanupSafetyTest extends TestCase
{
    public function testFailedPublicationRestoresOnlyTheOldInodeBeforeStageCleanup(): void
    {
        $cleanup = ['unlink', 'rmdir'];
        $refused = ['clear', 'command', 'publish', 'restore', 'unlink', 'rmdir'];
        $commandFault = ['clear', 'command', 'restore', 'unlink', 'rmdir'];
        $success = ['clear', 'command', 'publish', 'set-new', 'unlink', 'rmdir'];
        // Exact traces also pin ordinary failures, successful handoff and later cleanup precedence.
        $cases = [
            'success' => [true, $success, ''],
            'no-old' => [true, ['command', 'publish', 'set-new', 'unlink', 'rmdir'], ''],
            'mutable' => [true, ['command', 'publish', 'unlink', 'rmdir'], ''],
            'mutable+command' => [null, ['command', 'unlink', 'rmdir'], 'stage=immutable-clear rc=not-started'],
            'no-old+publish' => [null, ['command', 'publish', 'unlink', 'rmdir'], 'stage=immutable-clear rc=not-started'],
            'clear-false' => [false, array_merge(['clear'], $cleanup), 'stage=immutable-clear rc=not-started'],
            'clear' => [null, array_merge(['clear'], $cleanup), 'stage=immutable-clear rc=not-started'],
            'command' => [null, $commandFault, 'stage=immutable-clear rc=not-started'],
            'command+restore' => [null, $commandFault, 'stage=immutable-clear rc=not-started'],
            'command+unlink' => [null, $commandFault, 'stage=immutable-clear rc=not-started'],
            'publish-false' => [false, $refused, 'stage=publish rc=23'],
            'publish-false+restore-false' => [false, $refused, 'stage=publish rc=23'],
            'publish-false+restore' => [null, $refused, 'stage=publish rc=23'],
            'publish' => [null, $refused, 'stage=immutable-clear rc=not-started'],
            'publish+restore-false' => [null, $refused, 'stage=immutable-clear rc=not-started'],
            'publish+restore' => [null, $refused, 'stage=immutable-clear rc=not-started'],
            'publish+restore+unlink' => [null, $refused, 'stage=immutable-clear rc=not-started'],
            'publish+restore+rmdir' => [null, $refused, 'stage=immutable-clear rc=not-started'],
            'set-new-false' => [false, $success, 'stage=immutable-set rc=not-started'],
            'set-new' => [null, $success, 'stage=immutable-set rc=not-started'],
            'set-new+unlink' => [null, $success, 'stage=immutable-set rc=not-started'],
        ];
        $script = <<<'PHP'
namespace RootStageImmutableCleanupFixture;
function observe($phase, $result = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return in_array($phase.'-false', explode('+', $GLOBALS['case']), true) ? false : $result;
}
// All filesystem and process operations are intercepted; only memory streams are real.
function pmssRootStageContext($path) { return ['/fixture', '/fixture/fixtur01', 'fixtur01']; }
function function_exists($name) { return true; }
function posix_geteuid() { return 0; }
function pmssPathTargetIsSafe(...$args) { return true; }
function pmssPathWithinResolvedRoot(...$args) { return true; }
function pmssRootStageSweep($root) {}
function random_bytes($length) { return str_repeat('a', $length); }
function lstat($path) {
    if ($path === '/fixture/fixtur01/target') return in_array('no-old', explode('+', $GLOBALS['case']), true) ? false : $GLOBALS['old'];
    if (substr($path, -5) === '/file') return $GLOBALS['new'];
    return ['dev' => 7, 'mode' => 0040700, 'uid' => 0];
}
function mkdir($path, $mode) { return true; }
function fopen($path, $mode) { return $GLOBALS['handle'] = \fopen('php://memory', 'w+'); }
function pmssStreamHandleIsOpen($handle) { return is_resource($handle); }
function pmssStreamWriteAll($handle, $content) { return true; }
function fflush($handle) { return true; }
function chown($path, $owner) { return true; }
function chgrp($path, $group) { return true; }
function chmod($path, $mode) { return true; }
function pmssUserFileStatIsSingleRegular($stat) { return true; }
function pmssRootStagePublishCommand(...$args) { return observe('command', 'fixture-command'); }
function pmssRootStagePublishRun($command, &$failure) {
    $ok = observe('publish');
    $failure = $ok ? '' : 'stage=publish rc=23';
    return $ok;
}
function pmssRootStageImmutableSet($path, $expected, $enable) {
    $phase = !$enable ? 'clear' : ($expected['ino'] === 11 ? 'restore' : 'set-new');
    $GLOBALS['toggles'][] = [$phase, $path, $expected, $enable];
    return observe($phase);
}
function unlink($path) { return observe('unlink'); }
function rmdir($path) { return observe('rmdir'); }
PHP;
        $script .= \pmssTestingFunctionExport('pmssRootStageFileWrite', 'RootStageImmutableCleanupFixture');
        $script .= '$cases = '.var_export(array_keys($cases), true).';';
        $script .= <<<'PHP'
$rows = [];
$GLOBALS['old'] = ['dev' => 7, 'ino' => 11, 'mode' => 0100640, 'nlink' => 1];
$GLOBALS['new'] = ['dev' => 7, 'ino' => 19, 'mode' => 0100640, 'nlink' => 1];
foreach ($cases as $case) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case;
        $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['toggles'] = [];
        $GLOBALS['handle'] = null;
        foreach (explode('+', $case) as $phase) {
            if (!in_array($phase, ['success', 'no-old', 'mutable'], true) && strpos($phase, '-false') === false)
                $GLOBALS['faults'][$phase] = new $kind($phase);
        }
        $result = $caught = null; $failure = 'stale';
        try { $result = pmssRootStageFileWrite('/fixture/fixtur01/target', 'fixture bytes', 'root', 0640,
            !in_array('mutable', explode('+', $case), true), $failure); }
        catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$case, $result, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            $GLOBALS['events'], $GLOBALS['toggles'], $failure, is_resource($GLOBALS['handle'])];
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(count($cases) * 2, count($rows));
        foreach ($rows as [$case, $result, $sameFault, $events, $toggles, $failure, $open]) {
            [$expectedResult, $expectedEvents, $expectedFailure] = $cases[$case];
            $this->assertSame($expectedResult, $result, $case);
            $this->assertTrue($sameFault, $case);
            $this->assertSame($expectedEvents, $events, $case);
            $this->assertSame($expectedFailure, $failure, $case);
            $this->assertFalse($open, $case);
            $expectedToggles = [];
            foreach ($expectedEvents as $phase) {
                if (!in_array($phase, ['clear', 'restore', 'set-new'], true)) continue;
                $expectedToggles[] = [$phase, '/fixture/fixtur01/target',
                    ['dev' => 7, 'ino' => $phase === 'set-new' ? 19 : 11, 'mode' => 0100640, 'nlink' => 1], $phase !== 'clear'];
            }
            $this->assertSame($expectedToggles, $toggles, $case);
        }
    }
}
