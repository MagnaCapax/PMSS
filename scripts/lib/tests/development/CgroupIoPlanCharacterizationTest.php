<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/Manager.php';

/**
 * Locks scripts/lib/cgroup/Manager.php through its public CLI surface, including
 * scripts/lib/cgroup/cliInput.php, scripts/lib/cgroup/ioPlan.php and scripts/lib/cgroup/profiles.php.
 */
class CgroupIoPlanCharacterizationTest extends TestCase
{
    /** Exercise the legacy Manager entrypoint with every host operation intercepted. */
    private function observe(array $flags, string $device = '/dev/fixtur01'): array
    {
        $sys = new class($device) implements \PMSS\Cgroup\SystemInterface {
            public $events = [];
            private $device;
            public function __construct(string $device) { $this->device = $device; }
            public function getUid(string $user): int { $this->events[] = 'uid:'.$user; return 1000; }
            public function getCgroupMode(): string { $this->events[] = 'mode'; return 'v1'; }
            public function execute(string $command): ?string { $this->events[] = 'execute:'.$command; return null; }
            public function readFile(string $path): ?string { $this->events[] = 'read:'.$path; return null; }
            public function getTotalMemoryMiB(): int { $this->events[] = 'memory'; return 16384; }
            public function resolveDevice(string $device): string { $this->events[] = 'device:'.$device; return $this->device; }
            public function requireRoot(): void { $this->events[] = 'root'; }
        };
        $configDir = $this->pmssMakeTempDir('pmss-cgroup-io-plan-');
        [$rc, $out] = $this->pmssCaptureStdout(function () use ($sys, $flags): int {
            $manager = new \PMSS\Cgroup\Manager($sys, static function (string $label, string $command) use ($sys): int {
                $sys->events[] = 'step:'.$label;
                return 0;
            });
            return $manager->run(array_merge(['userConfigCgroup.php', 'fixtur01'], $flags));
        }, ['PMSS_CONFIG_DIR' => $configDir]);
        return [$rc, $out, $sys->events];
    }

    public function testProfileLatencyCostAndRepeatedScalarsKeepTheirOrder(): void
    {
        $this->assertSame([0,
            "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n"
            ."[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n[SKIP] io.cost requires cgroup v2\n"
            ."\n[Planned properties]\nCPUWeight=222\nIOWeight=200\n"
            ."[Planned IO properties]\nIOReadBandwidthMax=/dev/fixtur01 5M\nIOWriteBandwidthMax=/dev/fixtur01 10M\n"
            ."IOReadIOPSMax=/dev/fixtur01 100\nIOWriteIOPSMax=/dev/fixtur01 100\n"
            ."(dry-run or no --apply; not changing system)\n",
            ['uid:fixtur01', 'mode', 'device:/home', 'memory']],
            $this->observe(['--device=/home', '--io-profile=HDD', '--io-latency-ms=50', '--io-cost-qos=enable=1',
                '--cpu-weight=111', '--cpu-weight=222', '--apply', '--dry-run']));
    }

    public function testExplicitIoKeepsFlagFamilyGroupingAndUnknownProfiles(): void
    {
        $this->assertSame([0,
            "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n\n[Planned properties]\nCPUWeight=0\n"
            ."[Planned IO properties]\nIOReadBandwidthMax=/dev/fixtur03 2M\nIOReadBandwidthMax=/dev/fixtur01 1M\n"
            ."IOWriteBandwidthMax=/dev/fixtur02 3M\n(dry-run or no --apply; not changing system)\n",
            ['uid:fixtur01', 'mode', 'memory']],
            $this->observe(['--device=/dev/fixtur02', '--io-profile=unknown', '--cpu-weight=0',
                '--io-read-bw=/dev/fixtur03:2M', '--io-write-bw=/dev/fixtur02:3M', '--io-read-bw=/dev/fixtur01:1M']));
    }

    public function testHomeShorthandsResolveBeforeIdentityAndRetainClearSentinel(): void
    {
        $this->assertSame([0,
            "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n[Planned IO properties]\n"
            ."IOReadIOPSMax=/dev/fixtur01 infinity\nIOWriteIOPSMax=/dev/fixtur01 23\n"
            ."(dry-run or no --apply; not changing system)\n",
            ['device:/home', 'device:/home', 'uid:fixtur01', 'mode']],
            $this->observe(['--io-read-iops=/home:max', '--io-write-iops=/home:23']));
    }

    public function testRefusalsStopBeforeLaterProbesOrApply(): void
    {
        // A zero selector is still present for wipe exclusion, even though empty() treats it as absent.
        foreach ([['--wipe', '--device=0'], ['--wipe', '--io-profile=0'], ['--wipe', '--io-cost-model=0'],
            ['--wipe', '--io-cost-qos=0'], ['--cpu-weight=nope', '--device=/home']] as $flags) {
            $this->assertSame([2, "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n", ['uid:fixtur01', 'mode']],
                $this->observe($flags));
        }
        $this->assertSame([2, "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n",
            ['uid:fixtur01', 'mode', 'device:/home']], $this->observe(['--device=/home', '--cpu-weight=111'], '/unsafe'));
    }

    public function testImplicitUnsafeDeviceKeepsWarningAndV1Skip(): void
    {
        $this->assertSame([0,
            "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n"
            ."[WARN] IODeviceLatencyTargetSec skipped: unsafe /home backing device target\n"
            ."[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n",
            ['uid:fixtur01', 'mode', 'device:/home', 'memory']],
            $this->observe(['--io-latency-ms=50'], '/unsafe'));
    }

    public function testSkippedCostAndZeroProfileDoNotCreateApplyWork(): void
    {
        $this->assertSame([0,
            "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n[SKIP] io.cost requires cgroup v2\n",
            ['uid:fixtur01', 'mode', 'device:/home']],
            $this->observe(['--device=/home', '--io-profile=0', '--io-cost-qos=enable=1', '--apply']));
    }

    public function testApplyChecksPrivilegeAfterPlanningAndRunsTheExistingStep(): void
    {
        $this->assertSame([0,
            "user=fixtur01 uid=1000 slice=user-1000.slice mode=v1\n\n[Planned properties]\n"
            ."CPUWeight=300\nIOWeight=500\nTasksMax=8192\n",
            ['uid:fixtur01', 'mode', 'device:/home', 'memory', 'root', 'step:Applying cgroup properties']],
            $this->observe(['--device=/home', '--io-profile=bulk', '--apply']));
    }
}
