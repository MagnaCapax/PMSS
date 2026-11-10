<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Snapshot cleanup faults use memory streams and never create account files. */
class SupportSnapshotCleanupSafetyTest extends TestCase
{
    public function testIncompleteSnapshotRemovalSurvivesCloseFailures(): void
    {
        $script = <<<'PHP'
namespace SupportSnapshotCleanupFixture;
class RuntimeException extends \RuntimeException {}
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
}
// Isolate account paths, modes and ownership from the host filesystem.
function is_dir($path) { return true; }
function pmssPathTargetIsSafe(...$args) { return true; }
function pmssDirEnsureExists(...$args) { return true; }
function chmod($path, $mode) { return true; }
function clearstatcache(...$args) {}
function fileperms($path) { return substr($path, -4) === '.txt' ? 0600 : 0700; }
function fileowner($path) { return 4242; }
function posix_geteuid() { return 4242; }
function gmdate($format) { return '20010101-000000'; }
function getmypid() { return 123; }
function pmssFileOpenWithUmask($path, $mode, $mask) {
    observe('open');
    $GLOBALS['open'] = [$path, $mode, $mask];
    return $GLOBALS['case'] === 'open-false' ? false : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function pmssSupportStreamWriteAll($handle, $body, $context) {
    $GLOBALS['write'] = [$body, $context];
    observe('write');
    \fwrite($handle, $body);
}
function fflush($handle) { observe('flush'); return $GLOBALS['case'] !== 'flush-false'; }
function fclose($handle) {
    \fclose($handle);
    observe('close');
    return $GLOBALS['case'] !== 'close-false';
}
function unlink($path) {
    $GLOBALS['removed'][] = $path;
    observe('unlink');
    return $GLOBALS['case'] !== 'unlink-false';
}
function pmssRegularFilePathIsReadable($path) { observe('verify'); return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/diagnostics.php', 'SupportSnapshotCleanupFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_SUPPORT_SNAPSHOT_CLEANUP'), true) as [$case, $phase, $type, $closeType]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['faults'] = [];
    if ($phase !== '') $GLOBALS['faults'][$phase] = new $type($phase.' failure');
    if ($closeType !== '') $GLOBALS['faults']['close'] = new $closeType('close failure');
    $GLOBALS['events'] = $GLOBALS['removed'] = [];
    $GLOBALS['handle'] = $GLOBALS['open'] = $GLOBALS['write'] = null;
    $result = $caught = null;
    try {
        $result = pmssSupportSnapshotWrite(['home' => '/fixture/fixtur01', 'body' => "snapshot bytes\n"],
            ['snapshotDirectory' => '.support/requests']);
    } catch (\Throwable $error) { $caught = $error; }
    $expectedThrowable = $GLOBALS['faults']['close'] ?? $GLOBALS['faults'][$phase] ?? null;
    $live = is_resource($GLOBALS['handle']);
    if ($live) \fclose($GLOBALS['handle']);
    $results[] = [$result, $caught === null ? null : $caught->getMessage(),
        $expectedThrowable === null || $caught === $expectedThrowable, $live,
        $GLOBALS['events'], $GLOBALS['removed'], $GLOBALS['open'], $GLOBALS['write']];
}
echo json_encode($results);
PHP;
        $cases = [['ok', '', '', ''], ['open-false', '', '', ''],
            ['flush-false', '', '', ''], ['close-false', '', '', '']];
        // Check both throwable families at each boundary and during combined failures.
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach (['write', 'flush', 'close'] as $phase) $cases[] = ['ok', $phase, $type, ''];
            foreach (['RuntimeException', 'Error'] as $closeType) {
                foreach (['write', 'flush'] as $phase) $cases[] = ['ok', $phase, $type, $closeType];
            }
            $cases[] = ['flush-false', '', '', $type];
            $cases[] = ['unlink-false', 'write', $type, ''];
        }
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SUPPORT_SNAPSHOT_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        $path = '/fixture/fixtur01/.support/requests/request-20010101-000000-123.txt';
        $traces = [
            'ok' => ['open', 'write', 'flush', 'close', 'verify'],
            'open-false' => ['open'],
            'write' => ['open', 'write', 'close', 'unlink'],
            'flush' => ['open', 'write', 'flush', 'close', 'unlink'],
            'close' => ['open', 'write', 'flush', 'close'],
        ];
        foreach ($cases as $index => [$case, $phase, $type, $closeType]) {
            $failedFlush = $case === 'flush-false';
            $trace = $traces[$phase !== '' ? $phase : ($failedFlush ? 'flush' : ($case === 'open-false' ? $case : 'ok'))];
            $message = $closeType !== '' ? 'close failure' : ($phase !== '' ? $phase.' failure'
                : ($failedFlush ? 'Unable to flush support snapshot file.'
                    : ($case === 'open-false' ? 'Unable to create support snapshot file.' : null)));
            $removed = in_array('unlink', $trace, true) ? [$path] : [];
            $write = $case === 'open-false' ? null : ["snapshot bytes\n", 'support snapshot file'];
            $this->assertSame([$message === null ? $path : null, $message, true, false,
                $trace, $removed, [$path, 'x', 0077], $write], $results[$index], implode(':', $cases[$index]));
        }
    }
}
