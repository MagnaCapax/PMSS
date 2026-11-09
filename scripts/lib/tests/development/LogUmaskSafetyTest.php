<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime/filesystemStream.php';

/** Process-state fault coverage for scripts/lib/log.php without filesystem writes. */
class LogUmaskSafetyTest extends TestCase
{
    public function testLogOperationsRestoreUmaskAndPreserveOutcomes(): void
    {
        $script = <<<'PHP'
namespace LogUmaskFixture;
function observe($stage, array $request = []) {
    $GLOBALS['events'][] = [$stage, \umask(), $request];
    if ($GLOBALS['stage'] === $stage && $GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
}
function umask($mask = null) {
    if ($mask === null) return \umask();
    $GLOBALS['masks'][] = $mask;
    return \umask($mask);
}
function function_exists($name) { return $name === 'posix_geteuid' || \function_exists($name); }
function posix_geteuid() { return 1000; }
function stat($path) { return ['mode' => 01700]; }
function clearstatcache(...$args) {}
function pmssPathExistsOrLink($path) { return $GLOBALS['exists']; }
function lstat($path) { return $GLOBALS['exists'] ? ['mode' => 0100600, 'uid' => 1000, 'nlink' => 1] : false; }
function pmssPathSegmentsAreSafe(...$args) { return true; }
function pmssLockFilePathIsSafe($path) { return true; }
function is_dir($path) { return true; }
function is_link($path) { return false; }
function fopen($path, $mode) {
    observe('open', [$path, $mode]);
    if ($GLOBALS['stage'] === 'open-false') return false;
    $GLOBALS['exists'] = true;
    return $GLOBALS['stream'] = \fopen('php://memory', 'w+');
}
function fclose($stream) {
    \fclose($stream);
    observe('close');
    return true;
}
function date($format) { observe('format', [$format]); return 'stamp'; }
function pmssFileWriteComplete($path, $bytes, $flags) {
    observe('write', [$path, $bytes, $flags]);
    return $GLOBALS['stage'] !== 'write-false';
}
function chmod($path, $mode) { observe('chmod', [$path, $mode]); return true; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssFileOpenWithUmask', 'LogUmaskFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/log.php', 'LogUmaskFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_LOG_UMASK'), true) as [$kind, $mode, $initial, $stage, $type]) {
    $GLOBALS['stage'] = $stage;
    $GLOBALS['failure'] = $type === '' ? null : new $type('boundary failure');
    $GLOBALS['exists'] = $kind === 'append';
    $GLOBALS['events'] = $GLOBALS['masks'] = [];
    $GLOBALS['stream'] = null;
    $result = $caught = null;
    $previous = \umask($initial);
    try {
        try {
            $result = $kind === 'create' ? pmssLogSharedFileIsOwn('/fixture/events.log', $mode)
                : pmssLogAppendTimestampedLine('/fixture/events.log', 'ready', 'ignored', '!', $mode);
        } catch (\Throwable $error) { $caught = $error; }
        $results[] = [$result, \umask(), $GLOBALS['masks'], $GLOBALS['events'],
            !is_resource($GLOBALS['stream']), $caught === $GLOBALS['failure']];
    } finally { \umask($previous); }
}
echo json_encode($results);
PHP;
        $cases = $expected = [];
        foreach (['create', 'append'] as $kind) {
            foreach ($kind === 'create' ? [0600, 0644] : [null, 0600, 0644] as $mode) {
                foreach ([0022, 0077] as $initial) {
                    $faults = $kind === 'create' ? ['open', 'close'] : ['format', 'write', 'chmod'];
                    foreach (array_merge(['ok', $kind === 'create' ? 'open-false' : 'write-false'], $faults) as $stage) {
                        if ($stage === 'chmod' && $mode === null) continue;
                        $throws = in_array($stage, $faults, true);
                        foreach ($throws ? ['RuntimeException', 'Error'] : [''] as $type) {
                            $cases[] = [$kind, $mode, $initial, $stage, $type];
                            $mask = $mode === null ? $initial : 0777 & ~$mode;
                            $events = $kind === 'create'
                                ? [['open', $mask, ['/fixture/events.log', 'x']]]
                                : [['format', $mask, ['ignored']]];
                            if ($kind === 'create' && !in_array($stage, ['open', 'open-false'], true)) {
                                $events[] = ['close', $initial, []];
                            } elseif ($kind === 'append' && $stage !== 'format') {
                                $events[] = ['write', $mask, ['/fixture/events.log', 'stamp!ready'.PHP_EOL, FILE_APPEND | LOCK_EX]];
                                if ($stage !== 'write' && $stage !== 'write-false' && $mode !== null) {
                                    $events[] = ['chmod', $initial, ['/fixture/events.log', $mode]];
                                }
                            }
                            $expected[] = [$throws ? null : $stage === 'ok', $initial,
                                $mode === null ? [] : [$mask, $initial], $events, true, true];
                        }
                    }
                }
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_LOG_UMASK' => json_encode($cases),
        ]));
    }
}
