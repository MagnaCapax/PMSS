<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/storageBenchmark.php';
require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkPostInstallTest extends TestCase
{
    public function testSkipsWhenManagedUsersExist(): void
    {
        $marker = $this->pmssMakeTempDir('pmss-bench-post-install-', 0700).'/marker.json';
        $logs = [];
        $calls = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$logs, &$calls): void {
            $rc = \pmssStorageBenchmarkPostInstallRun(
                $this->pmssMakeArrayLogger($logs),
                static function () use (&$calls): int { $calls[] = true; return 0; },
                self::pmssMakeCallbacks(['users' => ['fixtur01']])['users']
            );
            $this->assertSame(0, $rc);
        });

        $this->assertSame([], $calls);
        $this->assertFalse(file_exists($marker));
        $this->assertStringContainsString('Managed users already exist', implode("\n", $logs));
    }

    public function testSkipsWhenSuccessMarkerExists(): void
    {
        $marker = $this->pmssMakeTempDir('pmss-bench-post-install-', 0700).'/marker.json';
        $this->pmssWriteFile($marker, "{}\n");
        $calls = [];
        $logs = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$calls, &$logs): void {
            $rc = \pmssStorageBenchmarkPostInstallRun(
                $this->pmssMakeArrayLogger($logs),
                static function () use (&$calls): int { $calls[] = true; return 0; },
                self::pmssMakeCallbacks(['users' => []])['users']
            );
            $this->assertSame(0, $rc);
        });

        $this->assertSame([], $calls);
        $this->assertStringContainsString('already completed', implode("\n", $logs));
    }

    public function testRunsBenchmarkLogsWarningsAndRecordsMarkerOnSuccess(): void
    {
        $marker = $this->pmssMakeTempDir('pmss-bench-post-install-', 0700).'/marker.json';
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

            $probes = self::pmssMakeCallbacks(['users' => [], 'devices' => 0]);
            $rc = \pmssStorageBenchmarkPostInstallRun($this->pmssMakeArrayLogger($logs), $runner, $probes['users'], $probes['devices']);
            $this->assertSame(0, $rc);
        });

        $this->assertSame(1, count($calls));
        $this->assertStringContainsAllStrings(['/scripts/util/storageBenchmark.php', '--require-idle', '--devices'], $calls[0][1]);
        $this->assertTrue(is_file($marker));
        $this->assertSame('storage_benchmark_post_install', $this->pmssReadJsonArrayFile($marker)['event'] ?? '');
        $this->assertStringContainsString('[storage-benchmark] WARN: /dev/sdb seqread < 60% median', implode("\n", $logs));
    }

    public function testFailedBenchmarkLeavesMarkerUnset(): void
    {
        $marker = $this->pmssMakeTempDir('pmss-bench-post-install-', 0700).'/marker.json';
        $logs = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker], function () use (&$logs): void {
            $runner = static function (): int {
                $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => 'Busy system'];
                return 2;
            };

            $probes = self::pmssMakeCallbacks(['users' => [], 'devices' => 0]);
            $rc = \pmssStorageBenchmarkPostInstallRun($this->pmssMakeArrayLogger($logs), $runner, $probes['users'], $probes['devices']);
            $this->assertSame(2, $rc);
        });

        $this->assertFalse(file_exists($marker));
        $this->assertStringContainsString('leaving marker unset', implode("\n", $logs));
    }

    public function testDeadlineScalesWithDeviceCountAndNeverDropsBelowTheGlobalOne(): void
    {
        $this->pmssWithEnv(['PMSS_COMMAND_TIMEOUT' => null], function (): void {
            $this->assertSame(1200, \pmssStorageBenchmarkPostInstallTimeoutSeconds(0));
            $this->assertSame(1200, \pmssStorageBenchmarkPostInstallTimeoutSeconds(-3));
            $this->assertSame(1200, \pmssStorageBenchmarkPostInstallTimeoutSeconds(5));
            $this->assertSame(2280, \pmssStorageBenchmarkPostInstallTimeoutSeconds(14));
        });
        $this->pmssWithEnv(['PMSS_COMMAND_TIMEOUT' => '5000'], function (): void {
            $this->assertSame(5000, \pmssStorageBenchmarkPostInstallTimeoutSeconds(14));
        });
    }

    public function testBenchmarkStepRunsUnderTheScaledDeadlineAndRestoresTheGlobalOne(): void
    {
        $marker = $this->pmssMakeTempDir('pmss-bench-post-install-', 0700).'/marker.json';
        $seen = [];
        $logs = [];

        $this->pmssWithEnv(['PMSS_STORAGE_BENCHMARK_INSTALL_MARKER' => $marker, 'PMSS_COMMAND_TIMEOUT' => '1300'], function () use (&$seen, &$logs): void {
            $runner = static function () use (&$seen): int {
                $seen[] = getenv('PMSS_COMMAND_TIMEOUT');
                $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => ''];
                return 124;
            };
            $probes = self::pmssMakeCallbacks(['users' => [], 'devices' => 14]);
            $rc = \pmssStorageBenchmarkPostInstallRun($this->pmssMakeArrayLogger($logs), $runner, $probes['users'], $probes['devices']);
            $this->assertSame(124, $rc);
            $this->assertSame('1300', getenv('PMSS_COMMAND_TIMEOUT'));
        });

        $this->assertSame(['2280'], $seen);
        $this->assertFalse(file_exists($marker));
    }
}
