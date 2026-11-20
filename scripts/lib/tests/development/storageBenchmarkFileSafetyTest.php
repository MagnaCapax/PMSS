<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/storageBenchmark/file.php';

class StorageBenchmarkFileSafetyTest extends TestCase
{
    public function testMalformedPathsCannotReserveOrRemoveFiles(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-benchmark-file-path-');
        $path = $dir.'/reserved.dat';
        $identity = \storageBenchmarkFileReserve($path);
        $this->assertTrue(is_array($identity));
        $this->pmssWriteFile($path, 'preserved');
        $missing = $dir.'/missing.dat';

        // Edge NULs must not select the existing entry or create the missing one.
        foreach (['', "\0", "\0".$path, $path."\0", $path."\0suffix",
            $dir."/reser\0ved.dat", "\0".$missing, $missing."\0"] as $unsafe) {
            $this->pmssAssertNoPhpWarnings(function () use ($unsafe, $identity): void {
                $this->assertSame(null, \storageBenchmarkFileReserve($unsafe));
                $this->assertFalse(\storageBenchmarkFileCleanup($unsafe, $identity));
            });
            $this->assertSame('preserved', file_get_contents($path));
            $this->assertFalse(file_exists($missing));
        }

        // Ordinary paths still reserve exclusively and remove only the reserved inode.
        $this->assertSame(null, \storageBenchmarkFileReserve($path));
        $this->assertTrue(\storageBenchmarkFileCleanup($path, $identity));
        $this->assertFalse(\storageBenchmarkFileCleanup($path, $identity));
    }

    public function testIncompleteSnapshotsCannotAuthorizeAllocationOrRemoval(): void
    {
        // All filesystem operations are intercepted; only memory streams are opened.
        $script = "namespace BenchmarkFileSnapshotFixture;\nrequire_once ".
            var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).";\n";
        $script .= <<<'PHP'
function fopen($path, $mode) { return $GLOBALS['handle'] = \fopen('php://memory', 'w+'); }
function fstat($handle) { return $GLOBALS['snapshot']; }
function fclose($handle) { ++$GLOBALS['closes']; return \fclose($handle); }
function clearstatcache($clear, $path) {}
function lstat($path) { return $GLOBALS['snapshot']; }
function unlink($path) { ++$GLOBALS['unlinks']; return true; }
// A partial fstat result must not emit an undefined-key warning either.
\set_error_handler(static function ($level, $message) { throw new \ErrorException($message, 0, $level); });
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark/file.php', 'BenchmarkFileSnapshotFixture');
        $script .= <<<'PHP'
$rows = [];
foreach ([false, [], ['dev' => 7], ['ino' => 19], ['dev' => null, 'ino' => 19],
    ['dev' => 7, 'ino' => null], ['dev' => null, 'ino' => null],
    ['dev' => 7, 'ino' => 19, 'size' => 0], ['dev' => 0, 'ino' => 0]] as $snapshot) {
    $GLOBALS['snapshot'] = $snapshot;
    $GLOBALS['closes'] = 0;
    $reserved = storageBenchmarkFileReserve('/unused/reserved.dat');
    $cleanups = [];
    foreach ([[], ['dev' => 7], ['ino' => 19], ['dev' => null, 'ino' => 19],
        ['dev' => 7, 'ino' => null], ['dev' => 7, 'ino' => 19],
        ['dev' => 0, 'ino' => 0], ['dev' => '7', 'ino' => 19]] as $identity) {
        $GLOBALS['unlinks'] = 0;
        $cleanups[] = [storageBenchmarkFileCleanup('/unused/reserved.dat', $identity), $GLOBALS['unlinks']];
    }
    $rows[] = [$reserved, $GLOBALS['closes'], is_resource($GLOBALS['handle']), $cleanups];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(9, count($rows));
        foreach ($rows as $index => [$reserved, $closes, $open, $cleanups]) {
            $expected = $index === 7 ? ['dev' => 7, 'ino' => 19] : ($index === 8 ? ['dev' => 0, 'ino' => 0] : null);
            $this->assertSame($expected, $reserved);
            $this->assertSame(1, $closes);
            $this->assertFalse($open);
            $expectedCleanups = array_fill(0, 8, [false, 0]);
            if ($index >= 7) $expectedCleanups[$index === 7 ? 5 : 6] = [true, 1];
            $this->assertSame($expectedCleanups, $cleanups);
        }
    }

    public function testReservationClosesItsStreamWhenMetadataInspectionThrows(): void
    {
        // Intercept native I/O in a child namespace; never allocate or run a benchmark.
        $script = "namespace BenchmarkFileLifetimeFixture;\nrequire_once ".
            var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).";\n";
        $script .= <<<'PHP'
function fopen($path, $mode) {
    $GLOBALS['handle'] = \fopen('php://temp', 'w+');
    return $GLOBALS['handle'];
}
function fstat($handle) {
    if ($GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
    return $GLOBALS['metadata'];
}
function fclose($handle) {
    $GLOBALS['closes']++;
    \fclose($handle);
    if ($GLOBALS['closeResult'] instanceof \Throwable) throw $GLOBALS['closeResult'];
    return $GLOBALS['closeResult'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark/file.php', 'BenchmarkFileLifetimeFixture');
        $script .= <<<'PHP'
$results = [];
// A false close cannot certify a reservation or swallow a metadata failure.
// A thrown close retains PHP's finally precedence over a metadata throwable.
foreach ([true, false, new \RuntimeException('close failure'), new \Error('close failure')] as $closeResult) {
    foreach ([new \RuntimeException('metadata failure'), new \Error('metadata failure'), null] as $failure) {
        foreach ([false, ['dev' => 7, 'ino' => 19]] as $metadata) {
            $GLOBALS['closeResult'] = $closeResult;
            $GLOBALS['failure'] = $failure;
            $GLOBALS['metadata'] = $metadata;
            $GLOBALS['closes'] = 0;
            $caught = null;
            $result = null;
            try {
                $result = storageBenchmarkFileReserve('/unused/reserved.dat');
            } catch (\Throwable $error) {
                $caught = $error;
            }
            $expectedFailure = $closeResult instanceof \Throwable ? $closeResult : $failure;
            $results[] = [$result, $caught === $expectedFailure, $GLOBALS['closes'], is_resource($GLOBALS['handle'])];
        }
    }
}
echo json_encode($results);
PHP;
        $this->assertSame(array_merge([
            [null, true, 1, false], [null, true, 1, false],
            [null, true, 1, false], [null, true, 1, false],
            [null, true, 1, false], [['dev' => 7, 'ino' => 19], true, 1, false],
        ], array_fill(0, 18, [null, true, 1, false])), $this->pmssRunInlinePhpJson($script));
    }
}
