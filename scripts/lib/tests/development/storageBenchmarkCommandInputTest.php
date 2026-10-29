<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkCommandInputTest extends TestCase
{
    public function testFioRejectsMalformedArgumentsBeforeAllocatingOrExecuting(): void
    {
        // Stub every side effect: these cases must never launch fio or create a file.
        $script = <<<'PHP'
namespace BenchmarkCommandInputFixture;
function pmssCreatePrivateTempFile($prefix) { $GLOBALS['events'][] = 'allocate'; return '/unused/fio.json'; }
function runCommand($command, $verbose) { $GLOBALS['events'][] = 'execute'; return 0; }
function file_get_contents($path) { $GLOBALS['events'][] = 'read'; return '{"jobs":[{}]}'; }
function unlink($path) { $GLOBALS['events'][] = 'unlink'; return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark.php',
            'BenchmarkCommandInputFixture', ['storageBenchmark/fio.php']);
        $script .= <<<'PHP'
$job = ['name' => 'fixture', 'rw' => 'read', 'bs' => '4k', 'bssplit' => '4k/100',
    'iodepth' => 1, 'numjobs' => 1, 'direct' => 1];
$cases = [];
foreach (['', "\0", "\0/unused/data", "/unused/data\0", "/unused/da\0ta", "/unused/data\0suffix"] as $path) {
    $GLOBALS['events'] = [];
    $cases[] = [fioRun($path, 4096, 1, $job), $GLOBALS['events']];
}
foreach (['name', 'rw', 'bs', 'bssplit'] as $key) {
    foreach (["\0", "\0".$job[$key], $job[$key]."\0", $job[$key]."\0suffix"] as $raw) {
        $GLOBALS['events'] = [];
        $cases[] = [fioRun('/unused/data', 4096, 1, array_replace($job, [$key => $raw])), $GLOBALS['events']];
    }
}
echo json_encode($cases);
PHP;
        $this->assertSame(array_fill(0, 22, [['ok' => false, 'error' => 'fio failed'], []]),
            $this->pmssRunInlinePhpJson($script));
    }

    public function testFioRejectsMalformedIntegerOptionsBeforeSideEffects(): void
    {
        $script = <<<'PHP'
namespace BenchmarkIntegerInputFixture;
function pmssCreatePrivateTempFile($prefix) { throw new \RuntimeException('unexpected allocation'); }
function runCommand($command, $verbose) { throw new \RuntimeException('unexpected execution'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark.php',
            'BenchmarkIntegerInputFixture', ['storageBenchmark/fio.php']);
        $script .= <<<'PHP'
$job = ['name' => 'fixture', 'rw' => 'read', 'iodepth' => 1, 'numjobs' => 1, 'direct' => 1];
$stream = fopen('php://memory', 'r+');
$cases = [];
try {
    // Every cast option must refuse malformed values without allocating or launching fio.
    foreach (['runtime', 'iodepth', 'numjobs', 'direct', 'rwmixread'] as $key) {
        foreach ([[], [1], new \stdClass(), $stream, "\0", "\0".'1', "1\0", "1\0suffix", "1\0002"] as $raw) {
            $cases[] = fioRun('/unused/data', 4096, 1, array_replace($job, [$key => $raw]));
        }
    }
} finally {
    fclose($stream);
}
echo json_encode($cases);
PHP;
        $this->assertSame(array_fill(0, 45, ['ok' => false, 'error' => 'fio failed']),
            $this->pmssRunInlinePhpJson($script));
    }

    public function testValidFioArgumentsKeepCommandBytesMetricsAndCleanup(): void
    {
        $script = <<<'PHP'
namespace BenchmarkValidCommandFixture;
function pmssCreatePrivateTempFile($prefix) { $GLOBALS['events'][] = $prefix; return '/unused/fio.json'; }
function runCommand($command, $verbose) { $GLOBALS['events'][] = [$command, $verbose]; return 0; }
function file_get_contents($path) { $GLOBALS['events'][] = ['read', $path]; return '{"jobs":[{"read":{"bw_bytes":1048576,"iops":2}}]}'; }
function unlink($path) { $GLOBALS['events'][] = ['unlink', $path]; return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark.php',
            'BenchmarkValidCommandFixture', ['storageBenchmark/fio.php']);
        $script .= <<<'PHP'
$job = ['name' => 'fixture name', 'rw' => 'randrw', 'bs' => '4k', 'bssplit' => '4k/100',
    'rwmixread' => 95, 'iodepth' => 2, 'numjobs' => 3, 'direct' => 1, 'runtime' => 7, 'readonly' => true];
$GLOBALS['events'] = [];
$result = fioRun("relative file's data", 4096, 1, $job);
$ordinary = [$result, $GLOBALS['events']];
// Integer casts retain legacy scalar conversions and optional-null fallback semantics.
$coercions = [];
foreach ([['runtime' => '7', 'iodepth' => ' 02 ', 'numjobs' => 3.9, 'direct' => true, 'rwmixread' => '095'],
    ['runtime' => null, 'iodepth' => 2.9, 'numjobs' => '03', 'direct' => '01', 'rwmixread' => 95.9]] as $values) {
    $GLOBALS['events'] = [];
    $coerced = fioRun("relative file's data", 4096, 7, array_replace($job, $values));
    $coercions[] = [$coerced, $GLOBALS['events']] === $ordinary;
}
echo json_encode([$ordinary, $coercions]);
PHP;
        $command = "fio --output-format=json --output '/unused/fio.json' --name='fixture name'"
            ." --filename='relative file'\\''s data' --size=4096 --time_based=1 --runtime=7 --rw='randrw'"
            ." --ioengine=libaio --iodepth=2 --numjobs=3 --direct=1 --group_reporting=1 --bs='4k'"
            ." --bssplit='4k/100' --rwmixread=95 --readonly";
        $this->assertSame([[
            ['ok' => true, 'result' => ['read_bw_MBps' => 1, 'write_bw_MBps' => 0,
                'read_iops' => 2, 'write_iops' => 0, 'read_p95_ms' => 0, 'write_p95_ms' => 0,
                'raw' => ['jobs' => [['read' => ['bw_bytes' => 1048576, 'iops' => 2]]]]]],
            ['fio-', [$command, true], ['read', '/unused/fio.json'], ['unlink', '/unused/fio.json']],
        ], [true, true]], $this->pmssRunInlinePhpJson($script));
    }

    public function testDdRejectsMalformedPathsAndPreservesValidCommands(): void
    {
        // Capture commands and supply a successful summary without reading a device.
        $script = <<<'PHP'
namespace BenchmarkDdInputFixture;
function runCommand($command, $verbose) {
    $GLOBALS['events'][] = [$command, $verbose];
    $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => "1048576 bytes copied, 0.5 s, 2.1 MB/s\n", 'stderr' => ''];
    return 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark.php',
            'BenchmarkDdInputFixture', ['storageBenchmark/device.php']);
        $script .= <<<'PHP'
$cases = [];
foreach (['', "\0", "\0/unused/data", "/unused/data\0", "/unused/da\0ta", "/unused/data\0suffix",
    '/dev/fixture', 'relative file', "relative file's data"] as $path) {
    $GLOBALS['events'] = [];
    $cases[] = [storageBenchmarkDdSeqread($path, 2, 3), $GLOBALS['events']];
}
echo json_encode($cases);
PHP;
        $expected = array_fill(0, 6, [['rc' => 1, 'mbps' => null, 'secs' => null], []]);
        foreach (["'/dev/fixture'", "'relative file'", "'relative file'\\''s data'"] as $quoted) {
            $expected[] = [['rc' => 0, 'mbps' => 2.1, 'secs' => 0.5],
                [["dd if={$quoted} of=/dev/null bs=1M count=2 skip=3 iflag=direct 2>&1", true]]];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }
}
