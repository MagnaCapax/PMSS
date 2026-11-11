<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/** File-backed benchmark faults release reservations without running disk probes. */
class StorageBenchmarkFileCleanupSafetyTest extends TestCase
{
    public function testFailuresCleanBeforePropagationAndShutdownRetainsItsRetry(): void
    {
        $script = <<<'PHP'
namespace BenchmarkFileCleanupFixture;
function observe($stage) {
    $GLOBALS['events'][] = $stage;
    if ($GLOBALS['stage'] === $stage) throw $GLOBALS['failure'];
}
function storageBenchmarkRequireCommandField($command, $label, $digits) { observe('free'); return 1000; }
function random_bytes($length) { return str_repeat('a', $length); }
function fopen($path, $mode) {
    observe('reserve');
    $GLOBALS['present'] = true;
    return \fopen('php://memory', 'w+');
}
function fstat($handle) { return ['dev' => 7, 'ino' => 19]; }
function clearstatcache($clear, $path) { $GLOBALS['events'][] = 'cleanup'; }
function lstat($path) {
    return $GLOBALS['present'] ? ['dev' => 7, 'ino' => $GLOBALS['replacement'] ? 20 : 19] : false;
}
function unlink($path) {
    $GLOBALS['events'][] = 'unlink';
    if ($GLOBALS['unlinkFails']) return false;
    $GLOBALS['present'] = false;
    return true;
}
// Capture shutdown callbacks so the child cannot delete a real path at process exit.
function register_shutdown_function($callback) { observe('register'); $GLOBALS['shutdown'] = $callback; }
function pmssCommandPath($name) { observe('lookup'); return '/fixture/fallocate'; }
function runCommand($command) { observe('preallocate'); return 0; }
function storageBenchmarkFileJobs($runtime) { observe('catalog'); return []; }
function storageBenchmarkEntryBase($stamp, $label, $id) { observe('base'); return []; }
function storageBenchmarkFioJobsRun(...$arguments) {
    observe('jobs');
    yield 'failed' => ['ok' => false];
    yield 'ready' => ['ok' => true, 'result' => array_combine(
        ['read_bw_MBps', 'write_bw_MBps', 'read_iops', 'write_iops', 'read_p95_ms', 'write_p95_ms'],
        [1, 2, 3, 4, 5, 6])];
    observe('after-yield');
}
PHP;
        foreach (['pmssFilesystemPathHasNulByte', 'pmssFilesystemStatSameInode'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'BenchmarkFileCleanupFixture');
        }
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark/file.php', 'BenchmarkFileCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['free', 'reserve', 'register', 'lookup', 'preallocate', 'catalog', 'base', 'jobs', 'after-yield', null] as $stage) {
    foreach ([new \RuntimeException('benchmark fault'), new \Error('benchmark fault')] as $failure) {
        foreach (['owned', 'replacement', 'unlink-false'] as $kind) {
            $GLOBALS['stage'] = $stage; $GLOBALS['failure'] = $failure;
            $GLOBALS['replacement'] = $kind === 'replacement'; $GLOBALS['unlinkFails'] = $kind === 'unlink-false';
            $GLOBALS['events'] = []; $GLOBALS['present'] = false; $GLOBALS['shutdown'] = null;
            $result = $caught = null;
            try {
                $result = storageBenchmarkRunFileTests('/fixture', '/fixture/events.jsonl', 'stamp', 'label', 'id', '/dev/fixture', 'ext4', 500, 30);
            } catch (\Throwable $error) { $caught = $error; }
            $beforeShutdown = [$GLOBALS['present'], $GLOBALS['events']];
            // A failed immediate unlink must remain retryable by the original callback.
            $GLOBALS['unlinkFails'] = false;
            if ($GLOBALS['shutdown'] !== null) ($GLOBALS['shutdown'])();
            $rows[] = [$stage, $kind, $result, $caught === ($stage === null ? null : $failure),
                $beforeShutdown, $GLOBALS['present'], $GLOBALS['events']];
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(60, count($rows));
        foreach ($rows as [$stage, $kind, $result, $sameFailure, $before, $present, $events]) {
            $trace = ['free', 'reserve', 'register', 'lookup', 'preallocate', 'catalog', 'base', 'jobs', 'after-yield'];
            if ($stage !== null) $trace = array_slice($trace, 0, array_search($stage, $trace, true) + 1);
            $reserved = !in_array($stage, ['free', 'reserve'], true);
            if ($reserved) {
                $trace[] = 'cleanup';
                if ($kind !== 'replacement') $trace[] = 'unlink';
            }
            $stillPresent = $reserved && $kind !== 'owned';
            $this->assertSame([$stillPresent, $trace], $before, (string) $stage.':'.$kind);
            if ($reserved && $stage !== 'register') {
                $trace[] = 'cleanup';
                if ($kind === 'unlink-false') $trace[] = 'unlink';
            }
            $this->assertSame($trace, $events);
            $this->assertSame($reserved && ($kind === 'replacement' || ($kind === 'unlink-false' && $stage === 'register')), $present);
            $this->assertTrue($sameFailure);
            $this->assertSame($stage === null ? [['ready', 1, 2, 3, 4, 5, 6]] : null, $result);
        }
    }
}
