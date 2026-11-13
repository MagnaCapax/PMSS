<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

/** Inventory boundary coverage for scripts/lib/storageHealth/nvme.php. */
class StorageHealthNvmePathSafetyTest extends TestCase
{
    public function testMalformedPathValuesReturnUnavailableBeforeCommandLookup(): void
    {
        $script = <<<'PHP'
namespace NvmePathValueFixture;
function pmssCommandPath($command) { throw new \RuntimeException('Unexpected command lookup'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageHealth/nvme.php',
            'NvmePathValueFixture');
        $script .= <<<'PHP'
$stream = fopen('php://memory', 'r+');
$closedStream = fopen('php://memory', 'r+');
fclose($closedStream);
// Objects are outside the inventory contract, even when they could supply a path.
$stringable = new class {
    public function __toString(): string { return '/dev/nvme0n1'; }
};
$exception = new class {
    public function __toString(): string { throw new \RuntimeException('Unexpected path conversion'); }
};
$error = new class {
    public function __toString(): string { throw new \Error('Unexpected path conversion'); }
};
$incomplete = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => false]);
$results = [];
set_error_handler(static function ($severity, $message, $file, $line): void {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
try {
    foreach ([null, [], ['/dev/nvme0n1'], new \stdClass(), $stringable, $exception,
        $error, $incomplete, $stream, $closedStream] as $value) {
        foreach (['path', 'kname', 'model', 'serial', 'rota', 'size'] as $field) {
            // Optional nulls remain valid; only the required path refuses null.
            if ($field !== 'path' && $value === null) continue;
            $results[] = pmssStorageHealthSnapshotNvme(
                array_replace(['path' => '/dev/nvme0n1'], [$field => $value]), [], 'fixture');
        }
    }
    $results[] = pmssStorageHealthSnapshotNvme([], [], 'fixture');
} finally {
    restore_error_handler();
    fclose($stream);
}
echo json_encode($results);
PHP;
        $this->assertSame(array_fill(0, 56, null), $this->pmssRunInlinePhpJson($script));
    }

    public function testScalarAndNullMetadataKeepProbeArgumentsAndPayload(): void
    {
        // Isolate the boundary without launching nvme or accessing a device.
        $script = <<<'PHP'
namespace NvmeMetadataValueFixture;
function pmssCommandPath($command) { return '/fixture/nvme'; }
function pmssStorageHealthProbeCommand($kind, $kname, $command) {
    $GLOBALS['probe'] = [$kind, $kname, $command];
    return ['command' => $command];
}
function pmssCommandCapture($command, $timeout) {
    $GLOBALS['capture'] = [$command, $timeout];
    return ['rc' => 0, 'stdout' => "temperature : 42 C\nmedia_errors : 0\n"];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageHealth/nvme.php',
            'NvmeMetadataValueFixture');
        $script .= <<<'PHP'
$rows = [[]];
foreach ([null, false, true, 0, 1, 1.5, NAN, INF, -INF, '', 'fixture'] as $value) {
    $rows[] = array_fill_keys(['kname', 'model', 'serial', 'rota', 'size'], $value);
}
$results = [];
foreach ($rows as $metadata) {
    $disk = ['path' => '/dev/nvme0n1'] + $metadata;
    $entry = pmssStorageHealthSnapshotNvme($disk, [], 'fixture');
    $command = "nvme smart-log '/dev/nvme0n1' 2>/dev/null";
    $results[] = [$entry === pmssStorageHealthParseNvmeOutput(
        "temperature : 42 C\nmedia_errors : 0\n", $disk, null, 'fixture'),
        $GLOBALS['probe'] === ['nvme', (string) ($metadata['kname'] ?? ''), $command],
        $GLOBALS['capture'] === [$command, 20]];
}
echo json_encode($results);
PHP;
        $this->assertSame(array_fill(0, 12, [true, true, true]), $this->pmssRunInlinePhpJson($script));
    }

    public function testScalarNonNvmePathsKeepUnavailableResult(): void
    {
        // Preserve legacy scalar casts before the existing NVMe eligibility check.
        foreach ([false, true, 0, 1, 1.5, NAN, INF, -INF, '', '/dev/sda'] as $path) {
            $this->assertSame(null, \pmssStorageHealthSnapshotNvme(['path' => $path], [], 'fixture'));
        }
    }

    public function testNulPathsReturnUnavailableWithoutLaunchingProbe(): void
    {
        $log = $this->pmssMakeTempFile('pmss-nvme-path-log-');
        $stub = $this->pmssMakeInvocationLogStub('nvme', $log, 'pmss-nvme-path-bin-');
        $this->pmssWithEnv(['PATH' => $stub], function () use ($log): void {
            foreach ([
                "\0/dev/nvme0n1", "/dev/nvme0n1\0", "/dev/nvme0\0n1",
                "/dev/\0nvme0n1", "/dev/nvme0n1\0ignored", " \0/dev/nvme0n1\0 ",
            ] as $path) {
                // The binary exists: a missing-command fallback cannot mask quoting faults.
                $this->assertSame(null, \pmssStorageHealthSnapshotNvme(
                    ['path' => $path, 'kname' => 'nvme0n1'], [], 'fixture'
                ));
                $this->assertSame('', file_get_contents($log));
            }
        });
    }

    public function testValidPathsKeepExactArgumentsAndSuccessfulPayload(): void
    {
        $log = $this->pmssMakeTempFile('pmss-nvme-path-argv-');
        $stub = $this->pmssMakeExecutableStub('nvme',
            "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($log)
                ."\nprintf '%s\\n' 'temperature : 42 C' 'media_errors : 0'\n",
            'pmss-nvme-path-valid-bin-');
        $base = $this->pmssMakeTempDir('pmss-nvme-path-valid-').'/nvme0n1';
        // Restrict PATH to our stub so no host nvme or flock command can run.
        $this->pmssWithEnv(['PATH' => $stub], function () use ($log, $base): void {
            foreach ([$base, $base.' with spaces', $base."'quoted", $base.';literal',
                " \t".$base."\t ", $base."\nline"] as $path) {
                $disk = ['path' => $path, 'kname' => 'nvme0n1', 'model' => 'Fixture'];
                $entry = \pmssStorageHealthSnapshotNvme($disk, [], 'fixture');
                $this->assertSame("smart-log\n".$path."\n", file_get_contents($log));
                $this->assertSame(\pmssStorageHealthParseNvmeOutput(
                    "temperature : 42 C\nmedia_errors : 0\n", $disk, null, 'fixture'
                ), $entry);
                $this->assertSame(42, $entry['metrics']['temperature']);
                $this->assertSame([], $entry['flags']);
            }
        });
    }
}
