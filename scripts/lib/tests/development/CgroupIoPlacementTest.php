<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/services/ioPlacement.php';
require_once __DIR__.'/../../cgroup/iopsCensus.php';

/** Hermetic placement-policy convergence and read-only rollout census coverage. */
final class CgroupIoPlacementTest extends TestCase
{
    private function fixture(): array
    {
        $root = $this->pmssMakeTempDir('pmss-io-placement-');
        $this->pmssWriteFile($root.'/template.systemd.io-placement.conf', "[Manager]\nDefaultIOAccounting=no\n");
        return [$root, $root.'/manager', $root.'/manager/90-pmss-io-placement.conf'];
    }

    public function testOnlyExplicitBooleanOptInOnV1InstallsTheDefault(): void
    {
        [$root, $directory, $path] = $this->fixture();
        $log = static function (string $message): void {};
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $root, 'PMSS_DRY_RUN' => '0'], function () use ($directory, $path, $log): void {
            foreach ([false, null, 0, 1, 'true', 'false'] as $value) {
                $this->assertTrue(\pmssCgroupIoPlacementConfigure(['cgroup' => ['ioSlicePlacement' => $value]], 'v1', $directory, $log));
                $this->assertFalse(is_dir($directory), 'disabled policy must not create a directory');
            }
            foreach (['v2', 'unknown'] as $mode) {
                $this->assertTrue(\pmssCgroupIoPlacementConfigure(['cgroup' => ['ioSlicePlacement' => true]], $mode, $directory, $log));
                $this->assertFalse(file_exists($path));
            }
            $this->assertTrue(\pmssCgroupIoPlacementConfigure(['cgroup' => ['ioSlicePlacement' => true]], 'v1', $directory, $log));
            $this->assertSame("[Manager]\nDefaultIOAccounting=no\n", $this->pmssReadFileOrEmpty($path));
            $this->assertSame(0644, fileperms($path) & 0777);
            $this->assertTrue(\pmssCgroupIoPlacementConfigure(['cgroup' => ['ioSlicePlacement' => true]], 'v1', $directory, $log));
            $this->assertTrue(\pmssCgroupIoPlacementConfigure([], 'v1', $directory, $log));
            $this->assertFalse(file_exists($path), 'turning the policy off removes only its managed default');
        });
    }

    public function testDryRunNeitherCreatesNorRemovesTheDropin(): void
    {
        [$root, $directory, $path] = $this->fixture();
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $root, 'PMSS_DRY_RUN' => '1'], function () use ($directory, $path): void {
            $log = static function (string $message): void {};
            $this->assertTrue(\pmssCgroupIoPlacementConfigure(['cgroup' => ['ioSlicePlacement' => true]], 'v1', $directory, $log));
            $this->assertFalse(is_dir($directory));
            $this->pmssWriteFile($path, 'keep');
            $this->assertTrue(\pmssCgroupIoPlacementConfigure([], 'v1', $directory, $log));
            $this->assertSame('keep', $this->pmssReadFileOrEmpty($path));
        });
    }

    public function testUnsafeTargetsAndMissingTemplateCannotEnablePlacement(): void
    {
        [$root, $directory, $path] = $this->fixture();
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $root, 'PMSS_DRY_RUN' => '0'], function () use ($root, $directory, $path): void {
            $log = static function (string $message): void {};
            $enabled = ['cgroup' => ['ioSlicePlacement' => true]];
            $this->pmssWriteFile($root.'/untouched', 'keep');
            mkdir($directory);
            $this->pmssCreateSymlinkOrSkip($root.'/untouched', $path);
            $this->assertFalse(\pmssCgroupIoPlacementConfigure($enabled, 'v1', $directory, $log));
            $this->assertFalse(\pmssCgroupIoPlacementConfigure([], 'v1', $directory, $log));
            $this->assertSame('keep', $this->pmssReadFileOrEmpty($root.'/untouched'));
            $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $root.'/missing'], function () use ($enabled, $root, $log): void {
                $this->assertFalse(\pmssCgroupIoPlacementConfigure($enabled, 'v1', $root.'/other', $log));
                $this->assertFalse(is_dir($root.'/other'));
            });
        });
    }

    public function testCensusRejectsInvalidSamplesAndPreservesZeroAndNearestRank(): void
    {
        $now = strtotime('2026-10-25T12:00:00');
        $rows = [];
        for ($n = 0; $n <= 20; $n++) {
            $rows[] = ['uid' => 1001, 'ts' => date('Y-m-d\TH:i:s', $now - 6000 + $n * 300), 'io_ops_read' => 150 * $n * ($n + 1)];
        }
        // Twenty increasing rates 1..20; P95 selects 19, independent of input order.
        $result = \pmssCgroupIopsCensusReadP95(array_reverse($rows), 1001, $now);
        $this->assertSame(20, $result['intervals']);
        $this->assertEquals(19, $result['p95']);
        foreach ([null, [], ['uid' => 1002], ['uid' => 1001, 'ts' => "bad\0date", 'io_ops_read' => 0],
            ['uid' => 1001, 'ts' => '2026-02-30T12:00:00', 'io_ops_read' => 0]] as $bad) {
            $this->assertSame(['p95' => null, 'intervals' => 0], \pmssCgroupIopsCensusReadP95([$bad], 1001, $now));
        }
        $zero = array_map(static function (array $row): array { $row['io_ops_read'] = 0; return $row; }, $rows);
        $this->assertEquals(0, \pmssCgroupIopsCensusReadP95($zero, 1001, $now)['p95']);
    }

    public function testCensusOmitsResetsLongGapsStaleFutureAndWrongCounterTypes(): void
    {
        $now = strtotime('2026-10-25T12:00:00');
        $samples = [];
        foreach ([[-90000, 0], [-1800, 600], [-600, 900], [-300, 0], [0, 300], [300, 999999]] as [$offset, $ops]) {
            $samples[] = ['uid' => 1001, 'ts' => date('Y-m-d\TH:i:s', $now + $offset), 'io_ops_read' => $ops];
        }
        $this->assertEquals(['p95' => 1, 'intervals' => 1], \pmssCgroupIopsCensusReadP95($samples, 1001, $now));
        foreach ([-1, '300', 3.5, null, INF] as $ops) {
            $samples[4]['io_ops_read'] = $ops;
            $this->assertSame(['p95' => null, 'intervals' => 0], \pmssCgroupIopsCensusReadP95($samples, 1001, $now));
        }
    }

    public function testCensusReaderSkipsMalformedPartialAndSymlinkedLogs(): void
    {
        [$root] = $this->fixture();
        $path = $root.'/metrics';
        $this->pmssWriteFile($path.'.1', "{\"uid\":1001}\n");
        $this->pmssWriteFile($path, "broken\n{\"uid\":1002}\n{\"uid\":1003}");
        $this->assertSame([['uid' => 1001], ['uid' => 1002]], iterator_to_array(\pmssCgroupIopsCensusSamples($path), false));
        $this->pmssCreateSymlinkOrSkip($path, $root.'/link');
        $this->assertSame([], iterator_to_array(\pmssCgroupIopsCensusSamples($root.'/link'), false));
    }
}
