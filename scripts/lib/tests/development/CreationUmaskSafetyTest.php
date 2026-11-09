<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime/filesystemStream.php';

/** Creation faults in user/directories.php and support/diagnostics.php use memory-only I/O. */
class CreationUmaskSafetyTest extends TestCase
{
    public function testCreationRestoresUmaskBeforeReturningLoggingOrPropagating(): void
    {
        $script = <<<'PHP'
namespace CreationUmaskFixture;
class RuntimeException extends \RuntimeException {}
function function_exists($name) {
    if (in_array($name, ['pmssEnsureDir', 'pmssEnsureUserHomeDir'], true)) {
        return \function_exists(__NAMESPACE__.'\\'.$name);
    }
    return $name === 'posix_geteuid' || \function_exists($name);
}
function posix_geteuid() { return 0; }
function umask($mask = null) {
    if ($mask === null) return \umask();
    $GLOBALS['masks'][] = $mask;
    return \umask($mask);
}
function observe($stage, array $arguments = []) {
    $GLOBALS['events'][] = [$stage, \umask(), $arguments];
    if (($arguments[0] ?? null) === $GLOBALS['boundary'] && $GLOBALS['failure'] !== null) {
        throw $GLOBALS['failure'];
    }
}
function is_dir($path) { return isset($GLOBALS['dirs'][$path]); }
function is_link($path) { return false; }
function file_exists($path) { return is_dir($path); }
function lstat($path) { return is_dir($path) ? ['uid' => 0, 'mode' => 0040755] : false; }
function mkdir($path, $mode) {
    observe('mkdir', [$path, $mode]);
    if ($path === $GLOBALS['boundary'] && $GLOBALS['fault'] === 'false') return false;
    $GLOBALS['dirs'][$path] = true;
    return true;
}
function pmssPathTargetIsSafe(...$arguments) { return true; }
function pmssDirEnsureExists(...$arguments) { return true; }
function chmod($path, $mode) { observe('chmod', [$path, $mode]); return true; }
function clearstatcache(...$arguments) {}
function fileperms($path) { return is_dir($path) ? 0700 : 0600; }
function fileowner($path) { return 0; }
function gmdate($format) { return 'stamp'; }
function getmypid() { return 37; }
function fopen($path, $mode) {
    observe('open', [$path, $mode]);
    if ($GLOBALS['fault'] === 'false') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function pmssSupportStreamWriteAll($handle, $bytes, $label) { observe('write', [$bytes, $label]); }
function fflush($handle) { observe('flush'); return true; }
function fclose($handle) { observe('close'); return \fclose($handle); }
function pmssRegularFilePathIsReadable($path) { return true; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssFileOpenWithUmask', 'CreationUmaskFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/directories.php', 'CreationUmaskFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/diagnostics.php', 'CreationUmaskFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_CREATION_UMASK'), true) as [$kind, $initial, $boundary, $fault]) {
    $GLOBALS['boundary'] = $boundary; $GLOBALS['fault'] = $fault;
    $GLOBALS['failure'] = $fault === 'exception' ? new \RuntimeException('creation fault')
        : ($fault === 'error' ? new \Error('creation fault') : null);
    $GLOBALS['dirs'] = ['/' => true, '/fixture' => true, '/fixture/.support' => true];
    $GLOBALS['events'] = $GLOBALS['masks'] = [];
    $GLOBALS['handle'] = null;
    $result = $caught = null;
    $previous = \umask($initial);
    try {
        try {
            $result = $kind === 'directory'
                ? pmssEnsureDir('/fixture/new/leaf', 0700, null, null,
                    static function ($message) { observe('log', [$message]); }, 0751)
                : pmssSupportSnapshotWrite(['home' => '/fixture', 'body' => 'snapshot bytes'],
                    ['snapshotDirectory' => '.support']);
        } catch (\Throwable $error) { $caught = $error; }
        // Observe state before fixture cleanup can conceal a mask or stream leak.
        $results[] = [$result, \umask(), $GLOBALS['masks'], $GLOBALS['events'],
            $caught === $GLOBALS['failure'], $caught === null ? null : $caught->getMessage(),
            is_resource($GLOBALS['handle'])];
    } finally {
        \umask($previous);
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode($results);
PHP;
        $cases = $expected = [];
        $snapshotPath = '/fixture/.support/request-stamp-37.txt';
        foreach ([0022, 0027, 0077] as $initial) {
            foreach (['/fixture/new', '/fixture/new/leaf', $snapshotPath] as $boundary) {
                foreach (['ok', 'false', 'exception', 'error'] as $fault) {
                    $directory = $boundary !== $snapshotPath;
                    $cases[] = [$directory ? 'directory' : 'snapshot', $initial, $boundary, $fault];
                    $throws = in_array($fault, ['exception', 'error'], true);
                    $events = $masks = [];
                    if ($directory) {
                        foreach (['/fixture/new' => 0751, '/fixture/new/leaf' => 0700] as $path => $mode) {
                            $events[] = ['mkdir', 0, [$path, $mode]];
                            array_push($masks, 0, $initial);
                            if ($path === $boundary && $throws) break;
                            $failed = $path === $boundary && $fault === 'false';
                            $events[] = ['log', $initial, [($failed ? '[WARN] Failed to create directory: '
                                : '[WARN] Created directory: ').$path]];
                            if ($failed) break;
                        }
                        $result = $throws ? null : $fault === 'ok';
                    } else {
                        $masks = [0077, $initial];
                        $events = [['chmod', $initial, ['/fixture/.support', 0700]],
                            ['open', 0077, [$snapshotPath, 'x']]];
                        if ($fault === 'ok') {
                            $events = array_merge($events, [
                                ['write', $initial, ['snapshot bytes', 'support snapshot file']],
                                ['flush', $initial, []], ['close', $initial, []],
                                ['chmod', $initial, [$snapshotPath, 0600]],
                            ]);
                        }
                        $result = $fault === 'ok' ? $snapshotPath : null;
                    }
                    $openFailed = !$directory && $fault === 'false';
                    $expected[] = [$result, $initial, $masks, $events, !$openFailed,
                        $throws ? 'creation fault' : ($openFailed ? 'Unable to create support snapshot file.' : null), false];
                }
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_CREATION_UMASK' => json_encode($cases),
        ]));
    }
}
