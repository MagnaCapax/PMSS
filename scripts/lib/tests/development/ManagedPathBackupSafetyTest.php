<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Backup fault injection uses memory streams, without touching managed files. */
class ManagedPathBackupSafetyTest extends TestCase
{
    public function testBackupClosesStreamsAndPreservesOutcomes(): void
    {
        $script = <<<'PHP'
namespace ManagedBackupFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $value;
}
// Keep path validation hermetic while exercising the real backup controller.
function is_dir($path) { return true; }
function is_link($path) { return false; }
function pmssUserFilePathIsSafe($path) { return true; }
function pmssRegularFilePathIsReadable($path) { return true; }
function pmssPathExistsOrLink($path) { return false; }
function fopen($path, $mode) {
    $role = $mode === 'rb' ? 'source' : 'target';
    observe('open-'.$role, null);
    $GLOBALS['opens'][] = [$path, $mode];
    return $GLOBALS['handles'][$role] = $GLOBALS['case'] === $role.'-unavailable'
        ? false : \fopen('php://memory', 'w+');
}
function pmssLockFileHandleMatchesPath($handle, $path) {
    return observe('match', $GLOBALS['case'] !== 'mismatch');
}
function fstat($handle) {
    return observe('stat', $GLOBALS['case'] === 'stat-failed' ? false : ['size' => 7, 'mode' => 0100640]);
}
function stream_copy_to_stream($source, $target) {
    return observe('copy', ['copy-failed' => false, 'short-copy' => 3][$GLOBALS['case']] ?? 7);
}
function fclose($handle) {
    $role = $handle === ($GLOBALS['handles']['target'] ?? null) ? 'target' : 'source';
    \fclose($handle);
    return observe('close-'.$role, $GLOBALS['case'] !== 'close-failed');
}
function chmod($path, $mode) {
    $GLOBALS['mode'] = $mode;
    return observe('chmod', $GLOBALS['case'] !== 'mode-failed');
}
function unlink($path) { $GLOBALS['events'][] = 'unlink'; return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/managedPath.php', 'ManagedBackupFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['success', 'source-unavailable', 'mismatch', 'target-unavailable', 'stat-failed',
    'copy-failed', 'short-copy', 'close-failed', 'mode-failed'];
$phases = ['open-source', 'match', 'open-target', 'stat', 'copy', 'close-target', 'close-source', 'chmod'];
foreach (array_merge($cases, $phases) as $case) {
    $phase = in_array($case, $phases, true) ? $case : null;
    foreach ($phase === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['phase'] = $phase;
        $GLOBALS['events'] = []; $GLOBALS['handles'] = []; $GLOBALS['opens'] = []; $GLOBALS['mode'] = null;
        $caught = null; $result = null; $logs = [];
        try {
            $result = pmssCreateManagedPathBackup('/fixture/managed.conf', 'fixture config',
                static function ($line) use (&$logs) { $logs[] = $line; }, 'stamp');
        } catch (\Throwable $error) { $caught = $error; }
        $live = array_filter($GLOBALS['handles'], 'is_resource');
        // Observe leaked descriptors before releasing them in the fixture.
        foreach ($live as $handle) \fclose($handle);
        $results[] = [$case, $result, $caught === $failure, count($live), $GLOBALS['events'], $logs,
            $GLOBALS['opens'], $GLOBALS['mode']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $trace = ['open-source', 'match', 'open-target', 'stat', 'copy', 'close-target', 'close-source', 'chmod'];
        $backup = '/fixture/managed.conf.pmss-backup-stamp';
        $openWarning = '[WARN] Unable to open fixture config for backup at /fixture/managed.conf';
        $writeWarning = '[WARN] Unable to create fixture config backup at '.$backup;
        $cases = [
            'success' => [$backup, $trace, []],
            'source-unavailable' => ['', ['open-source'], [$openWarning]],
            'mismatch' => ['', ['open-source', 'match', 'close-source'], [$openWarning]],
            'target-unavailable' => ['', ['open-source', 'match', 'open-target', 'close-source'], [$writeWarning]],
        ];
        foreach (['stat-failed', 'copy-failed', 'short-copy', 'close-failed', 'mode-failed'] as $case) {
            $cases[$case] = ['', array_merge(array_slice($trace, 0, $case === 'mode-failed' ? 8 : 7), ['unlink']), [$writeWarning]];
        }
        foreach ($trace as $phase) {
            $events = array_slice($trace, 0, array_search($phase, $trace, true) + 1);
            if (in_array($phase, ['stat', 'copy'], true)) $events[] = 'close-target';
            if (in_array($phase, ['match', 'open-target', 'stat', 'copy', 'close-target'], true)) $events[] = 'close-source';
            $cases[$phase] = [null, $events, []];
        }
        $index = 0;
        foreach ($cases as $case => [$expected, $events, $logs]) {
            $opens = [];
            foreach (['source' => ['/fixture/managed.conf', 'rb'], 'target' => [$backup, 'xb']] as $role => $args) {
                if (in_array('open-'.$role, $events, true) && $case !== 'open-'.$role) $opens[] = $args;
            }
            $mode = in_array('chmod', $events, true) ? 0640 : null;
            for ($kind = 0; $kind < ($expected === null ? 2 : 1); $kind++) {
                $this->assertSame([$case, $expected, true, 0, $events, $logs, $opens, $mode], $results[$index++], $case);
            }
        }
        $this->assertSame($index, count($results));
    }
}
