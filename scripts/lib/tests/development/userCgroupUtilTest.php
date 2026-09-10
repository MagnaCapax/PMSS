<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/SystemInterface.php';
require_once dirname(__DIR__, 2).'/cgroup/Manager.php';

use PMSS\Cgroup\Manager;
use PMSS\Cgroup\SystemInterface;

class UserCgroupUtilTest extends TestCase
{
    /** Lock profile precedence, repeated flags, output order, and return codes. */
    public function testCliPipelineSnapshot(): void
    {
        $configDir = $this->pmssMakeCgroupPolicyConfigDir([
            'memoryHighMiB' => 600, 'cpuWeight' => 180,
            'mounts' => ['/home' => ['ioWeight' => 75, 'readBw' => '12M']],
            'profiles' => ['cpu' => ['LOW' => 80], 'io' => [
                'HDD' => ['cpuWeight' => 225, 'readIops' => 0, 'writeBw' => '7M'],
                'archive' => ['tasksMax' => 9000, 'readBw' => '9M'],
            ]],
        ]);
        $cases = [
            ['--cpu-weight=50', '--cpu-weight=75'],
            ['--cpu-profile=LOW', '--mem-profile=heavy', '--tasks-profile=high'],
            ['--cpu-profile=unknown'], ['--device=/home', '--io-profile=HDD'],
            ['--device=/home', '--io-profile=nvme'], ['--device=/home', '--io-profile=bulk'],
            ['--device=/home', '--io-profile=archive'], ['--device=/home', '--io-profile=unknown'],
            ['--defaults', '--device=/home', '--io-profile=hdd', '--io-weight=777'],
            ['--io-write-iops=/home:max', '--io-read-iops=/home:0', '--io-read-bw=/dev/sdb:3M', '--io-read-bw=/dev/sdc:4M'],
            ['--io-latency-ms=50'], ['--cpu-quota-percent=infinity'],
        ];
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $configDir], function () use ($cases): void {
            $snapshot = [];
            $manager = new Manager($this->makeSystemStub('/dev/sdb'), static function (): int { return 0; });
            foreach ($cases as $flags) {
                $snapshot[] = $this->pmssCaptureStdout(static function () use ($manager, $flags): int {
                    return $manager->run(array_merge(['userConfigCgroup.php', 'testuser', '--apply'], $flags));
                });
            }
            $this->assertSame('2043e5da85f94201d8ee42562f1d08dc7d293f9fd42b6715663bc499b00c7abc', hash('sha256', serialize($snapshot)));
        });
    }
    /**
     * Create a manager instance backed by a no-op system implementation.
     *
     * computeSetProps does not depend on the SystemInterface, but wiring
     * a stub keeps the construction path identical to real usage while
     * remaining hermetic.
     */
    private function makeManager(): Manager
    {
        return $this->makeManagerWithSystem($this->makeSystemStub());
    }

    private function makeManagerWithSystem(SystemInterface $stub): Manager
    {
        return new Manager($stub);
    }

    /** Build the shared no-op cgroup system stub used by dry-run manager tests. */
    private function makeSystemStub(string $resolvedDevice = ''): SystemInterface
    {
        return new class($resolvedDevice) implements SystemInterface {
            /** @var bool */
            public $resolved = false;

            /** @var string */
            private $resolvedDevice;

            public function __construct(string $resolvedDevice) { $this->resolvedDevice = $resolvedDevice; }
            public function getCgroupMode(): string { return 'v2'; }
            public function getUid(string $user): int { return 1000; }
            public function execute(string $command): ?string { return ''; }
            public function readFile(string $path): ?string { return null; }
            public function getTotalMemoryMiB(): int { return 0; }

            public function resolveDevice(string $device): string
            {
                $this->resolved = true;
                return $this->resolvedDevice;
            }

            public function requireRoot(): void {}
        };
    }

    public function testComputePropsClampsMemory(): void
    {
        $mgr = $this->makeManager();
        $sys = 4096; // MiB
        $p = $mgr->computeSetProps(['memory-high' => 100, 'memory-max' => 10000], $sys);
        $this->assertEquals('250M', $p['MemoryHigh']);
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') <= (int)floor($sys*0.95));
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') >= 250);
    }

    public function testComputePropsDerivesMaxFromHighWhenMaxMissing(): void
    {
        $mgr = $this->makeManager();
        $sys = 8192;
        $p = $mgr->computeSetProps(['memory-high' => 500], $sys);
        $mh = (int)rtrim($p['MemoryHigh'],'M');
        $mm = (int)rtrim($p['MemoryMax'],'M');
        $this->assertEquals((int)floor($mh*1.25), $mm, 'expected 1.25x high');
        $this->assertTrue($mm <= (int)floor($sys*0.95));
    }

    public function testComputePropsAcceptsCpuIoTasks(): void
    {
        $mgr = $this->makeManager();
        $this->assertSame(
            ['CPUWeight' => 300, 'IOWeight' => 250, 'TasksMax' => 8192],
            $mgr->computeSetProps(['cpu-weight'=>300,'io-weight'=>250,'tasks-max'=>8192], 32768)
        );
    }

    public function testComputePropsDefaultsHighWhenOnlyMaxProvided(): void
    {
        $mgr = $this->makeManager();
        $p = $mgr->computeSetProps(['memory-max'=>3000], 16000);
        $this->assertTrue(isset($p['MemoryHigh']), 'MemoryHigh should be set when only max provided');
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') >= (int)rtrim($p['MemoryHigh'],'M'));
    }

    public function testComputePropsHandlesZeroSysMemGracefully(): void
    {
        $mgr = $this->makeManager();
        $p = $mgr->computeSetProps(['memory-high'=>250,'memory-max'=>500], 0);
        $this->assertEquals('250M', $p['MemoryHigh']);
        $this->assertEquals('500M', $p['MemoryMax']);
    }

    public function testComputePropsSupportsCpuQuota(): void
    {
        $mgr = $this->makeManager();
        $withQuota = $mgr->computeSetProps(['memory-high'=>400,'cpu-quota-percent'=>85], 16000);
        $this->assertEquals('85%', $withQuota['CPUQuota']);
        $infinity = $mgr->computeSetProps(['memory-high'=>400,'cpu-quota-percent'=>0], 16000);
        $this->assertEquals('', $infinity['CPUQuota']);
    }

    public function testIoPolicyProfileKeepsOnlyPositiveValues(): void
    {
        $cfgDir = $this->pmssMakeCgroupPolicyConfigDir([
            'profiles' => [
                'io' => [
                    'archive' => [
                        'cpuWeight' => 250,
                        'readBw' => '9M',
                        'writeBw' => '',
                        'readIops' => 44,
                        'writeIops' => 0,
                    ],
                ],
            ],
        ]);

        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $cfgDir], function (): void {
            $mgr = $this->makeManager();
            list($rc, $out) = $this->pmssCaptureStdout(function () use ($mgr): int {
                return $mgr->run(['userConfigCgroup.php', 'testuser', '--dry-run', '--device=/dev/sdb', '--io-profile=archive']);
            });

            $this->assertEquals(0, $rc);
            $this->assertStringContainsString('CPUWeight=250', $out);
            $this->assertStringContainsString('IOReadBandwidthMax=/dev/sdb 9M', $out);
            $this->assertStringContainsString('IOReadIOPSMax=/dev/sdb 44', $out);
            $this->assertStringNotContainsString('IOWriteBandwidthMax=/dev/sdb', $out);
            $this->assertStringNotContainsString('IOWriteIOPSMax=/dev/sdb', $out);
        });
    }

    public function testRejectsUnsafeDeviceSelectorBeforeResolution(): void
    {
        $stub = $this->makeSystemStub('/dev/sdb');

        $mgr = $this->makeManagerWithSystem($stub);
        list($rc) = $this->pmssCaptureStdout(function () use ($mgr): int {
            return $mgr->run(['userConfigCgroup.php', 'testuser', '--dry-run', '--device=/mnt/data set', '--io-profile=hdd']);
        });

        $this->assertEquals(2, $rc);
        /** @var object{resolved: bool} $stub */
        $this->assertFalse($stub->resolved, 'unsafe device selectors must not be resolved through findmnt');
    }

    public function testRejectsExplicitIoDeviceWithDotSegments(): void
    {
        $mgr = $this->makeManager();
        list($rc) = $this->pmssCaptureStdout(function () use ($mgr): int {
            return $mgr->run(['userConfigCgroup.php', 'testuser', '--dry-run', '--io-read-bw=/dev/../etc/passwd:20M']);
        });

        $this->assertEquals(2, $rc);
    }

    public function testIoLatencyRejectsUnsafeResolvedHomeDeviceTargets(): void
    {
        foreach (['/dev/bad target', '/dev/../bad'] as $device) {
            $stub = $this->makeSystemStub($device);
            $mgr = $this->makeManagerWithSystem($stub);
            list($rc, $out) = $this->pmssCaptureStdout(function () use ($mgr): int {
                return $mgr->run(['userConfigCgroup.php', 'testuser', '--dry-run', '--io-latency-ms=50']);
            });

            $this->assertEquals(0, $rc);
            $this->assertStringContainsString('IODeviceLatencyTargetSec skipped', $out);
            $this->assertStringNotContainsString('IODeviceLatencyTargetSec='.$device, $out);
        }
    }

    public function testPolicyDefaultsSkipUnsafeDeviceTargets(): void
    {
        $cfgDir = $this->pmssMakeCgroupPolicyConfigDir([
            'mounts' => [
                '/dev/bad target' => ['ioWeight' => 200],
            ],
        ]);

        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $cfgDir], function (): void {
            $mgr = $this->makeManager();
            list($rc, $out) = $this->pmssCaptureStdout(function () use ($mgr): int {
                return $mgr->run(['userConfigCgroup.php', 'testuser', '--dry-run', '--defaults']);
            });

            $this->assertEquals(0, $rc);
            $this->assertStringNotContainsString('IODeviceWeight=/dev/bad target', $out);
        });
    }
}
