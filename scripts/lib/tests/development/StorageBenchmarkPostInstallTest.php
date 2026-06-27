<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/storageBenchmark.php';
require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkPostInstallTest extends TestCase
{
    private function markerPath(): string
    {
        return $this->pmssMakeTempDir('pmss-bench-post-install-', 0700).'/marker.json';
    }

    public function testSkipsWhenManagedUsersExist(): void
    {
        $marker = $this->markerPath();
        $logs = [];
        $calls = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$logs, &$calls): void {
            $rc = \pmssStorageBenchmarkPostInstallRun(
                $this->pmssMakeArrayLogger($logs),
                static function () use (&$calls): int { $calls[] = true; return 0; },
                static function (): array { return ['alice']; }
            );
            $this->assertSame(0, $rc);
        });

        $this->assertSame([], $calls);
        $this->assertFalse(file_exists($marker));
        $this->assertStringContainsString('Managed users already exist', implode("\n", $logs));
    }

    public function testSkipsWhenSuccessMarkerExists(): void
    {
        $marker = $this->markerPath();
        file_put_contents($marker, "{}\n");
        $calls = [];
        $logs = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$calls, &$logs): void {
            $rc = \pmssStorageBenchmarkPostInstallRun(
                $this->pmssMakeArrayLogger($logs),
                static function () use (&$calls): int { $calls[] = true; return 0; },
                static function (): array { return []; }
            );
            $this->assertSame(0, $rc);
        });

        $this->assertSame([], $calls);
        $this->assertStringContainsString('already completed', implode("\n", $logs));
    }

    public function testRunsBenchmarkLogsWarningsAndRecordsMarkerOnSuccess(): void
    {
        $marker = $this->markerPath();
        $calls = [];
        $logs = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$calls, &$logs): void {
            $runner = static function (string $description, string $command) use (&$calls): int {
                $calls[] = [$description, $command];
                $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = [
                    'stdout' => "== Per-device read-only benchmarks ==\nWARN: /dev/sdb seqread < 60% median\n",
                    'stderr' => '',
                ];
                return 0;
            };

            $rc = \pmssStorageBenchmarkPostInstallRun($this->pmssMakeArrayLogger($logs), $runner, static function (): array { return []; });
            $this->assertSame(0, $rc);
        });

        $this->assertSame(1, count($calls));
        $this->assertStringContainsString('/scripts/util/storageBenchmark.php', $calls[0][1]);
        $this->assertStringContainsString('--require-idle', $calls[0][1]);
        $this->assertStringContainsString('--devices', $calls[0][1]);
        $this->assertTrue(is_file($marker));
        $this->assertSame('storage_benchmark_post_install', $this->pmssReadJsonArrayFile($marker)['event'] ?? '');
        $this->assertStringContainsString('[storage-benchmark] WARN: /dev/sdb seqread < 60% median', implode("\n", $logs));
    }

    public function testFailedBenchmarkLeavesMarkerUnset(): void
    {
        $marker = $this->markerPath();
        $logs = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$logs): void {
            $runner = static function (): int {
                $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => 'Busy system'];
                return 2;
            };

            $rc = \pmssStorageBenchmarkPostInstallRun($this->pmssMakeArrayLogger($logs), $runner, static function (): array { return []; });
            $this->assertSame(2, $rc);
        });

        $this->assertFalse(file_exists($marker));
        $this->assertStringContainsString('leaving marker unset', implode("\n", $logs));
    }
}
