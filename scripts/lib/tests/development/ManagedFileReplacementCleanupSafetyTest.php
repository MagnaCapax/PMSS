<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Unpublished staging cleanup in scripts/lib/lighttpd/userFileReplace.php survives close faults. */
class ManagedFileReplacementCleanupSafetyTest extends TestCase
{
    public function testCloseFaultsStillAttemptStagingRemovalWithoutRemovingPublishedFiles(): void
    {
        $script = <<<'PHP'
namespace ManagedReplacementCleanupFixture;
// Distinct fault objects expose which operation won when several stages throw.
function observe($phase, $result = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    if (in_array($phase.'-false', explode('+', $GLOBALS['case']), true)) return false;
    if ($GLOBALS['case'] === $phase.'-null') return null;
    return $result;
}
// Only memory streams are real; no filesystem path is opened, renamed or removed.
function pmssManagedAccountHomePath($path) { return false; }
function pmssUserFilePathIsSafe($path) {
    return observe(++$GLOBALS['pathChecks'] === 1 ? 'path-initial' : 'path-final');
}
function pmssUserFileTempCreate($path, &$tmp) {
    $tmp = '/fixture/target.stage';
    return observe('create') === null ? null : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function pmssUserFileHandleWriteComplete($handle, $content) {
    $GLOBALS['content'] = $content;
    return observe('write');
}
// Metadata preparation must keep receiving the opened inode, never the staging name.
function pmssUserFileHandleProcPath($handle) { return observe('opened-path', '/proc/self/fd/73'); }
function pmssUserFileHandleMatchesEntry($handle, $path) {
    $GLOBALS['verified'] = $path;
    return observe('verify');
}
function rename($source, $target) {
    $GLOBALS['rename'] = [$source, $target];
    return $GLOBALS['published'] = observe('rename');
}
function fclose($handle) {
    $GLOBALS['events'][] = 'close';
    // Exercise faults both before release and after the stream is already closed.
    if (isset($GLOBALS['faults']['close-before'])) throw $GLOBALS['faults']['close-before'];
    \fclose($handle);
    if (isset($GLOBALS['faults']['close'])) throw $GLOBALS['faults']['close'];
    return !in_array('close-false', explode('+', $GLOBALS['case']), true);
}
function unlink($path) { $GLOBALS['unlinks'][] = $path; return observe('unlink'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileReplace.php', 'ManagedReplacementCleanupFixture');
        $script .= <<<'PHP'
// Refusals, ordinary false cleanup results, single faults and combined faults.
$cases = ['success', 'create-null', 'write-false', 'opened-path-null', 'prepare-false',
    'verify-false', 'path-final-false', 'rename-false', 'close-false', 'write-false+unlink-false',
    'write-false+close-false', 'write', 'opened-path', 'prepare', 'verify', 'path-final',
    'rename', 'close', 'close-before', 'write+close', 'write+close-before', 'write+unlink',
    'write-false+close', 'write-false+unlink', 'write+close+unlink', 'write+close-before+unlink'];
$rows = [];
foreach ($cases as $case) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case;
        $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['unlinks'] = [];
        $GLOBALS['handle'] = $GLOBALS['content'] = $GLOBALS['verified'] = $GLOBALS['rename'] = null;
        $GLOBALS['prepared'] = null;
        $GLOBALS['pathChecks'] = 0; $GLOBALS['published'] = false;
        foreach (explode('+', $case) as $phase) {
            if ($phase !== 'success' && strpos($phase, '-false') === false && strpos($phase, '-null') === false)
                $GLOBALS['faults'][$phase] = new $kind($phase);
        }
        $result = $caught = null;
        try {
            $result = pmssReplaceUserFile('/fixture/target', "fixture\0bytes", static function ($path) {
                $GLOBALS['prepared'] = $path;
                return observe('prepare');
            });
        } catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$case, $result, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            is_resource($GLOBALS['handle']), $GLOBALS['published'], $GLOBALS['events'],
            $GLOBALS['unlinks'], $GLOBALS['content'], $GLOBALS['verified'], $GLOBALS['rename'], $GLOBALS['prepared']];
        // Bound fixture resources even when the injected close fails before closing.
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(52, count($rows));
        $normal = ['path-initial', 'create', 'write', 'opened-path', 'prepare', 'verify', 'path-final', 'rename'];
        foreach ($rows as [$case, $result, $sameFault, $open, $published, $events, $unlinks, $content, $verified, $rename, $prepared]) {
            $first = explode('+', $case)[0];
            $phase = str_replace(['-false', '-null'], '', $first);
            $position = array_search($phase, $normal, true);
            $trace = $position === false ? $normal : array_slice($normal, 0, $position + 1);
            // Successful rename clears the name even when a later close throws.
            $didPublish = $position === false;
            if ($case !== 'create-null') {
                $trace[] = 'close';
                if (!$didPublish) $trace[] = 'unlink';
            }
            $ordinary = strpos($case, '+') === false && ($case === 'success' || substr($case, -6) === '-false' || substr($case, -5) === '-null');
            $ordinary = $ordinary || in_array($case, ['write-false+unlink-false', 'write-false+close-false'], true);
            $this->assertSame($ordinary ? $didPublish : null, $result, $case);
            $this->assertTrue($sameFault, $case);
            $this->assertSame(strpos($case, 'close-before') !== false, $open, $case);
            $this->assertSame($didPublish, $published, $case);
            $this->assertSame($trace, $events, $case);
            $this->assertSame($didPublish || $case === 'create-null' ? [] : ['/fixture/target.stage'], $unlinks, $case);
            $this->assertSame($case === 'create-null' ? null : "fixture\0bytes", $content, $case);
            $this->assertSame(in_array('verify', $trace, true) ? '/fixture/target.stage' : null, $verified, $case);
            $this->assertSame(in_array('rename', $trace, true) ? ['/fixture/target.stage', '/fixture/target'] : null, $rename, $case);
            $this->assertSame(in_array('prepare', $trace, true) ? '/proc/self/fd/73' : null, $prepared, $case);
        }
    }
}
