<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Verify acquisition ownership without opening or removing managed files. */
class ManagedFileHandleSafetyTest extends TestCase
{
    public function testVerificationFailuresCloseStreamsBeforeOwnershipTransfers(): void
    {
        $script = <<<'PHP'
namespace ManagedHandleFixture;
function inspect($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $value;
}
function pmssUserFilePathIsSafe($path) { return true; }
function pmssStreamHandleIsOpen($handle) { return is_resource($handle) && get_resource_type($handle) === 'stream'; }
function lstat($path) {
    return $GLOBALS['afterOpen'] ? inspect('entry-stat', []) : [];
}
function pmssUserFileStatIsSingleRegular($stat) {
    if (!$GLOBALS['afterOpen']) return true;
    $phase = isset($stat['opened']) ? 'opened-regular' : 'entry-regular';
    return inspect($phase, $GLOBALS['case'] !== $phase.'-failed');
}
function pmssLockFileHandleMatchesPath($handle, $path, $entry, &$opened) {
    $opened = ['opened' => true];
    return inspect('inode', $GLOBALS['case'] !== 'mismatch');
}
function random_bytes($length) { return str_repeat('a', $length); }
function fopen($path, $mode) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['opens'][] = [$path, $mode, umask()];
    $GLOBALS['afterOpen'] = true;
    return $GLOBALS['handle'] = $GLOBALS['case'] === 'open-failed' ? false : \fopen('php://memory', 'w+');
}
function pmssPathExistsOrLink($path) { return false; }
function fclose($handle) { $GLOBALS['events'][] = 'close'; return \fclose($handle); }
function unlink($path) { $GLOBALS['unlinks'][] = $path; return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileHandle.php', 'ManagedHandleFixture');
        $script .= <<<'PHP'
$results = [];
$oldUmask = umask(0022);
try {
    foreach (['existing', 'temporary'] as $kind) {
        $phases = ['entry-stat', 'entry-regular', 'inode', 'opened-regular'];
        $cases = ['success', 'open-failed', 'entry-regular-failed', 'mismatch', 'opened-regular-failed'];
        foreach (array_merge($cases, $phases) as $case) {
            $phase = in_array($case, $phases, true) ? $case : null;
            foreach ($phase === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
                $GLOBALS['case'] = $case; $GLOBALS['phase'] = $phase; $GLOBALS['failure'] = $failure;
                $GLOBALS['events'] = []; $GLOBALS['opens'] = []; $GLOBALS['unlinks'] = [];
                $GLOBALS['afterOpen'] = false; $GLOBALS['handle'] = null;
                $tmp = null; $result = null; $caught = null;
                try {
                    $result = $kind === 'existing' ? pmssUserFileOpenVerified('/fixture/state', 'rbn')
                        : pmssUserFileTempCreate('/fixture/state', $tmp);
                } catch (\Throwable $error) { $caught = $error; }
                // Observe leaks and transferred ownership before the fixture releases either.
                $live = is_resource($GLOBALS['handle']);
                $results[] = [$kind, $case, $caught === $failure, $live, $live && $result === $GLOBALS['handle'],
                    $tmp, umask(), $GLOBALS['events'], $GLOBALS['opens'], $GLOBALS['unlinks']];
                if ($live) \fclose($GLOBALS['handle']);
            }
        }
    }
} finally {
    umask($oldUmask);
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $checks = ['entry-stat', 'entry-regular', 'inode', 'opened-regular'];
        $index = 0;
        foreach (['existing', 'temporary'] as $kind) {
            $path = $kind === 'existing' ? '/fixture/state' : '/fixture/state.pmss-tmp-'.str_repeat('61', 6);
            $opens = [[$path, $kind === 'existing' ? 'rbn' : 'xb', $kind === 'existing' ? 0022 : 0077]];
            $cases = ['success' => 4, 'open-failed' => 0, 'entry-regular-failed' => 2,
                'mismatch' => 3, 'opened-regular-failed' => 4];
            foreach ($checks as $position => $phase) $cases[$phase] = $position + 1;
            foreach ($cases as $case => $count) {
                $throws = in_array($case, $checks, true);
                $events = array_merge(['open'], array_slice($checks, 0, $count));
                if ($case !== 'success' && $case !== 'open-failed') $events[] = 'close';
                $success = $case === 'success';
                $tmp = $kind === 'temporary' && $success ? $path : null;
                for ($type = 0; $type < ($throws ? 2 : 1); $type++) {
                    $this->assertSame([$kind, $case, true, $success, $success, $tmp, 0022, $events, $opens, []],
                        $results[$index++], $kind.': '.$case);
                }
            }
        }
        $this->assertSame($index, count($results));
    }
}
