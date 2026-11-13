<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

/** Device-path boundary coverage for scripts/lib/storageHealth/nvme.php. */
class StorageHealthNvmePathSafetyTest extends TestCase
{
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
