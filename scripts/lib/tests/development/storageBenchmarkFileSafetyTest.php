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
        file_put_contents($path, 'preserved');
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
    return \fclose($handle);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark/file.php', 'BenchmarkFileLifetimeFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([new \RuntimeException('metadata failure'), new \Error('metadata failure'), null] as $failure) {
    foreach ([false, ['dev' => 7, 'ino' => 19]] as $metadata) {
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
        $results[] = [$result, $caught === $failure, $GLOBALS['closes'], is_resource($GLOBALS['handle'])];
    }
}
echo json_encode($results);
PHP;
        $this->assertSame([
            [null, true, 1, false], [null, true, 1, false],
            [null, true, 1, false], [null, true, 1, false],
            [null, true, 1, false], [['dev' => 7, 'ino' => 19], true, 1, false],
        ], $this->pmssRunInlinePhpJson($script));
    }
}
