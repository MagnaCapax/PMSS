<?php

namespace PMSS\Tests\Development;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/SystemInterface.php';
require_once __DIR__.'/../../cgroup/Manager.php';

use PMSS\Cgroup\SystemInterface;
use PMSS\Cgroup\Manager;
use PMSS\Tests\TestCase;

class MockSystem implements SystemInterface
{
    public $cgroupMode = 'v2';
    public $users = ['testuser' => 1000, 'other' => 1001];
    public $files = [];
    public $commands = [];
    public $executedCommands = [];
    public $totalMemMiB = 16384; // 16 GB default
    public $findmnt = [];
    public $env = [];

    public function getCgroupMode(): string { return $this->cgroupMode; }
    public function getUid(string $user): int { return $this->users[$user] ?? -1; }
    public function execute(string $command): ?string {
        $this->executedCommands[] = $command;
        foreach ($this->commands as $k => $v) {
            if (strpos($command, $k) !== false) return $v;
        }
        return null;
    }
    public function readFile(string $path): ?string { return $this->files[$path] ?? null; }
    public function getTotalMemoryMiB(): int { return $this->totalMemMiB; }
    public function resolveDevice(string $device): string { return $this->findmnt[$device] ?? '/dev/sda'; }
    public function requireRoot(): void { /* no-op for tests */ }
}

class CgroupUserConfigTest extends TestCase
{
    /** @var MockSystem|null */
    private $sys;
    /** @var Manager|null */
    private $mgr;
    /** @var string */
    private $configDir = '';

    /**
     * Ensure the manager/system fixtures are initialised for the current test.
     *
     * Some harnesses may bypass setUp(); this helper keeps the tests robust
     * by lazily constructing MockSystem/Manager when needed.
     */
    private function ensureManager(): void
    {
        if ($this->configDir === '') {
            $this->pmssAssignTempDirProperty('configDir', 'pmss-cgroup-config-', 0700);
            $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $this->configDir, 'PMSS_DRY_RUN' => null]);
        }

        if ($this->sys === null || $this->mgr === null) {
            $this->sys = new MockSystem();
            $this->mgr = new Manager($this->sys);
        }
    }

    protected function setUp(): void
    {
        $this->sys = null;
        $this->mgr = null;
        $this->configDir = '';
        $this->ensureManager();
    }

    private function runMgr(array $args)
    {
        $this->ensureManager();
        // Prepend dummy script name
        array_unshift($args, 'userConfigCgroup.php');
        list($rc, $out) = $this->pmssCaptureStdout(function () use ($args) { return $this->mgr->run($args); });
        return ['rc' => $rc, 'out' => $out];
    }

    /** Run the manager and assert the common rc/output contract. */
    private function assertRunOutput(array $args, array $required = array(), array $forbidden = array(), int $expectedRc = 0): array
    {
        $res = $this->runMgr($args);
        $this->assertEquals($expectedRc, $res['rc']);
        $this->assertStringContainsAndOmitsStrings($required, $forbidden, $res['out']);
        return $res;
    }

    public function testUsage()
    {
        // Manager prints usage to STDERR; we only assert on the return code
        // here because stdout capture does not include STDERR in the harness.
        $this->assertRunOutput(array(), array(), array(), 2);
    }

    public function testUnknownUser()
    {
        $this->assertRunOutput(array('ghost'), array(), array(), 1);
    }

    public function testValidUserStatus()
    {
        $this->assertRunOutput(array('testuser'), array('slice=user-1000.slice', '[Config]', '[Status]'));
    }

    // -- Memory Calculation & Clamping Tests --

    public function testMemoryDefaultCalculation()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, ['memoryHighMiB' => 1000], 0700);
        
        // Max should be derived: 1000 * 1.25 = 1250
        $this->assertRunOutput(array('testuser', '--defaults'), array('MemoryHigh=1000M', 'MemoryMax=1250M'));
    }

    public function testMemoryClampEnforcement()
    {
        // 16GB RAM. Explicit High 2000. Explicit Max 10000.
        // Clamp: Max <= High + 2048 = 4048.
        $this->assertRunOutput(array('testuser', '--memory-high=2000', '--memory-max=10000'), array('MemoryHigh=2000M', 'MemoryMax=4048M'));
    }

    public function testMemoryClampWithSmallValues()
    {
        // High 500. Max 1000. 1000 < 500+2048. OK.
        $this->assertRunOutput(array('testuser', '--memory-high=500', '--memory-max=1000'), array('MemoryHigh=500M', 'MemoryMax=1000M'));
    }

    public function testMemoryFloor()
    {
        // High 100 (below 250 floor).
        // Max derived from clamped high: 250 * 1.25 = 312
        $this->assertRunOutput(array('testuser', '--memory-high=100'), array('MemoryHigh=250M', 'MemoryMax=312M'));
    }

    public function testMemoryMaxOnlyDerivesMemoryHighSnapshot(): void
    {
        $res = $this->runMgr(['testuser', '--memory-max=3000']);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."\n"
            ."[Planned properties]\n"
            ."MemoryHigh=1638M\n"
            ."MemoryMax=3000M\n"
            ."CPUWeight=324\n"
            ."IOWeight=200\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIoLatencyDryRunPlanSnapshot(): void
    {
        $res = $this->runMgr(['testuser', '--dry-run', '--io-latency-ms=50']);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."[Planned IO properties]\n"
            ."IODeviceLatencyTargetSec=/dev/sda 50ms\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testScalarOptionParserKeepsInlineOnlyContract(): void
    {
        $this->assertRunOutput(array('testuser', '--memory-high', '600'), array('[Config]'), array('[Planned properties]'));
        $this->assertRunOutput(array('testuser', '--memory-high=600', '--memory-high'), array('MemoryHigh=600M'));
    }

    public function testMemory95PercentCap()
    {
        // 1GB System RAM. Cap ~972M.
        $this->sys->totalMemMiB = 1024;
        // Request High 900. Max 2000.
        // High 900 OK.
        // Max cap at 972.
        // Max should be capped by system RAM (~972) not 900+2048
        $this->assertRunOutput(array('testuser', '--memory-high=900', '--memory-max=2000'), array('MemoryHigh=900M', 'MemoryMax=972M'));
    }

    // -- CPU Quota Tests --

    public function testCpuQuotaInfinity()
    {
        // Should result in empty string.
        $this->assertRunOutput(array('testuser', '--cpu-quota-percent=infinity'), array('CPUQuota='), array('CPUQuota=infinity'));
    }

    public function testCpuQuotaZero()
    {
        $this->assertRunOutput(array('testuser', '--cpu-quota-percent=0'), array('CPUQuota='), array('CPUQuota=0'));
    }

    public function testCpuQuotaValue()
    {
        $this->assertRunOutput(array('testuser', '--cpu-quota-percent=200'), array('CPUQuota=200%'));
    }

    public function testRejectsInvalidScalarAndDeviceValues()
    {
        foreach ([
            'cpu weight string' => ['--cpu-weight=abc'],
            'decimal memory high' => ['--memory-high=12.5'],
            'cpu quota string' => ['--cpu-quota-percent=fast'],
            'malformed IO bandwidth' => ['--io-read-bw=/dev/sda'],
            'relative IO bandwidth device' => ['--io-read-bw=tmp/device:5M'],
            'non-device IO bandwidth path' => ['--io-read-bw=/tmp/device:5M'],
            'whitespace in device value' => ['--device=/dev/sda bad', '--io-profile=hdd'],
        ] as $label => $args) {
            $this->assertRunOutput(array_merge(['testuser'], $args), array(), array(), 2);
        }
    }

    public function testApplyBuildsShellSafeIoPropertyArguments()
    {
        putenv('PMSS_DRY_RUN=1');
        $this->assertRunOutput(array('testuser', '--apply', '--io-read-bw=/dev/sda:5M'), array("'IOReadBandwidthMax=/dev/sda 5M'"));
    }

    public function testApplyWipePropagatesRevertFailure(): void
    {
        $steps = [];
        $this->mgr = new Manager($this->sys, static function (string $description, string $command) use (&$steps): int {
            $steps[] = [$description, $command];
            return $description === 'Reverting user slice' ? 1 : 0;
        });

        $res = $this->runMgr(['testuser', '--apply', '--wipe']);

        $this->assertEquals(1, $res['rc']);
        $this->assertEquals(2, count($steps));
        $this->assertStringContainsString('systemctl', $steps[0][1]);
        $this->assertStringNotContainsString('|| true', $steps[0][1]);
        if (!isset($steps[1])) {
            $this->fail('Expected unlimit step after revert failure.');
        }
        $this->assertSame('Unlimiting core properties', $steps[1][0]);
    }

    public function testApplyIoCostFailurePropagatesFromRunner(): void
    {
        $steps = [];
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";
        $this->mgr = new Manager($this->sys, static function (string $description, string $command) use (&$steps): int {
            $steps[] = [$description, $command];
            return $description === 'Applying io.cost setting' ? 1 : 0;
        });

        $res = $this->runMgr(['testuser', '--apply', '--io-cost-qos=enable=1 ctrl=user']);

        $this->assertEquals(1, $res['rc']);
        $this->assertEquals(1, count($steps));
        $this->assertSame('Applying io.cost setting', $steps[0][0]);
        $this->assertStringContainsString('[ERR] io.cost path not writable:', $steps[0][1]);
        $this->assertStringContainsString('exit 1', $steps[0][1]);
    }

    public function testMixedFlagPlanSnapshotLocksParsingPrecedence(): void
    {
        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--dry-run',
            '--memory-high',
            '999',
            '--memory-high=600',
            '--memory-max=900',
            '--cpu-weight=111',
            '--cpu-weight=222',
            '--device=/dev/sdb',
            '--io-profile=bulk',
            '--io-read-bw=/dev/sda:5M',
            '--io-read-bw=/dev/sdb:6M',
            '--io-write-iops=/dev/sda:9',
        ]);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."\n"
            ."[Planned properties]\n"
            ."MemoryHigh=600M\n"
            ."MemoryMax=900M\n"
            ."CPUWeight=222\n"
            ."IOWeight=500\n"
            ."TasksMax=8192\n"
            ."[Planned IO properties]\n"
            ."IOReadBandwidthMax=/dev/sda 5M\n"
            ."IOReadBandwidthMax=/dev/sdb 6M\n"
            ."IOWriteIOPSMax=/dev/sda 9\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIoFlagPlanSnapshotLocksParserPropertyOrder(): void
    {
        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--dry-run',
            '--io-write-bw=/dev/sdb:10M',
            '--io-read-bw=/dev/sda:5M',
            '--io-write-iops=/dev/sdc:7',
            '--io-read-iops=/dev/sdd:8',
        ]);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."[Planned IO properties]\n"
            ."IOReadBandwidthMax=/dev/sda 5M\n"
            ."IOWriteBandwidthMax=/dev/sdb 10M\n"
            ."IOReadIOPSMax=/dev/sdd 8\n"
            ."IOWriteIOPSMax=/dev/sdc 7\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIopsClearSentinelResolvesHomeDeviceToInfinity(): void
    {
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--dry-run',
            '--io-read-iops=/home:max',
            '--io-write-iops=/home:max',
        ]);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."[Planned IO properties]\n"
            ."IOReadIOPSMax=/dev/md0 infinity\n"
            ."IOWriteIOPSMax=/dev/md0 infinity\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIopsPositiveIntShorthandResolvesHomeDeviceToValue(): void
    {
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--dry-run',
            '--io-read-iops=/home:2000',
            '--io-write-iops=/home:1000',
        ]);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."[Planned IO properties]\n"
            ."IOReadIOPSMax=/dev/md0 2000\n"
            ."IOWriteIOPSMax=/dev/md0 1000\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIopsHomeShorthandRejectsNonNumericNonMaxValue(): void
    {
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--dry-run',
            '--io-read-iops=/home:foobar',
        ]);

        $this->assertTrue($res['rc'] !== 0);
    }

    public function testApplyIopsClearSentinelClearsStaleSystemdDropin(): void
    {
        $steps = [];
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->mgr = new Manager($this->sys, function (string $description, string $command) use (&$steps): int {
            $steps[] = [$description, $command];
            return 0;
        });

        $res = $this->runMgr(['testuser', '--apply', '--io-read-iops=/home:max']);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame([
            [
                'Applying cgroup properties',
                \pmssBuildCommand('systemctl', [
                    'set-property',
                    'user-1000.slice',
                    'IOReadIOPSMax=/dev/md0 infinity',
                ]),
            ],
        ], $steps);
    }

    public function testIoLatencyDefaultsToHomeBackingDevice()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';

        $this->assertRunOutput(array('testuser', '--io-latency-ms=50'), array('IODeviceLatencyTargetSec=/dev/md0 50ms'));
    }

    public function testIoLatencyRequiresPositiveInteger()
    {
        $this->assertRunOutput(array('testuser', '--io-latency-ms=0'), array(), array(), 2);
    }

    public function testDefaultsApplyPolicyLatencyToHomeDevice()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, ['ioLatencyMs' => 45], 0700);
        $this->sys->findmnt['/home'] = '/dev/md0';

        $this->assertRunOutput(array('testuser', '--defaults'), array('IODeviceLatencyTargetSec=/dev/md0 45ms'));
    }

    public function testIoLatencySkippedOnCgroupV1()
    {
        $this->sys->cgroupMode = 'v1';
        $this->assertRunOutput(
            array('testuser', '--io-latency-ms=50'),
            array('IODeviceLatencyTargetSec requires cgroup v2'),
            array('IODeviceLatencyTargetSec=/dev/')
        );
    }

    public function testIoCostQosDefaultsToHomeDeviceMajorMinor()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $this->assertRunOutput(
            array('testuser', '--io-cost-qos=enable=1 ctrl=user'),
            array('[Planned io.cost writes]', '/sys/fs/cgroup/io.cost.qos <= 9:0 enable=1 ctrl=user')
        );
    }

    public function testIoCostAcceptsMatchingExplicitMajorMinor()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $this->assertRunOutput(
            array('testuser', '--io-cost-qos=9:0 enable=1 ctrl=user'),
            array('[Planned io.cost writes]', '/sys/fs/cgroup/io.cost.qos <= 9:0 enable=1 ctrl=user')
        );
    }

    public function testIoCostRejectsMismatchedExplicitMajorMinor()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $this->assertRunOutput(
            array('testuser', '--io-cost-qos=8:0 enable=1 ctrl=user'),
            array('io.cost skipped: invalid io.cost.qos setting'),
            array('[Planned io.cost writes]')
        );
    }

    public function testIoCostSkippedWhenBfqSchedulerActive()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";
        $this->sys->commands['grep -l'] = "/sys/class/block/sda/queue/scheduler\n";

        $this->assertRunOutput(
            array('testuser', '--io-cost-qos=enable=1 ctrl=user'),
            array('io.cost skipped: BFQ scheduler active'),
            array('[Planned io.cost writes]')
        );
    }

    public function testIoCostSkippedOnCgroupV1()
    {
        $this->sys->cgroupMode = 'v1';
        $this->assertRunOutput(array('testuser', '--io-cost-qos=enable=1 ctrl=user'), array('io.cost requires cgroup v2'));
    }

    public function testIoCostRejectsControlCharacters()
    {
        foreach (['--io-cost-qos', '--io-cost-model'] as $flag) {
            $this->assertRunOutput(array('testuser', $flag."=enable=1\nctrl=user"), array(), array(), 2);
        }
    }

    // -- Profile Tests --

    public function testCpuProfileLow()
    {
        $this->assertRunOutput(array('testuser', '--cpu-profile=low'), array('CPUWeight=50'));
    }

    public function testCpuProfileHigh()
    {
        $this->assertRunOutput(array('testuser', '--cpu-profile=high'), array('CPUWeight=300'));
    }

    public function testMemProfileLow()
    {
        $this->assertRunOutput(array('testuser', '--mem-profile=low'), array('MemoryHigh=250M'));
    }

    public function testIoProfileHdd()
    {
        $this->sys->findmnt['/dev/sdb'] = '/dev/sdb';
        $res = $this->runMgr(['testuser', '--device=/dev/sdb', '--io-profile=hdd']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."\n"
            ."[Planned properties]\n"
            ."IOWeight=200\n"
            ."[Planned IO properties]\n"
            ."IOReadBandwidthMax=/dev/sdb 5M\n"
            ."IOWriteBandwidthMax=/dev/sdb 10M\n"
            ."IOReadIOPSMax=/dev/sdb 100\n"
            ."IOWriteIOPSMax=/dev/sdb 100\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIoProfileBulk()
    {
        $this->assertRunOutput(
            array('testuser', '--device=/dev/sdb', '--io-profile=bulk'),
            array('IOWeight=500', 'CPUWeight=300', 'TasksMax=8192')
        );
    }

    public function testDefaultsApplyPolicyMountIoPairsWithoutExplicitIoInput()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, [
            'mounts' => [
                '/home' => ['ioWeight' => 333, 'readBw' => '6M', 'readIops' => 123],
            ],
        ], 0700);
        $this->sys->findmnt['/home'] = '/dev/md0';

        $this->assertRunOutput(
            array('testuser', '--defaults'),
            array('IODeviceWeight=/dev/md0 333', 'IOReadBandwidthMax=/dev/md0 6M', 'IOReadIOPSMax=/dev/md0 123')
        );
    }

    public function testDefaultsSkipPolicyMountIoPairsWhenExplicitIoInputIsPresent(): void
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, [
            'mounts' => [
                '/home' => ['ioWeight' => 333, 'readBw' => '6M', 'readIops' => 123],
            ],
        ], 0700);
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr(['testuser', '--defaults', '--io-read-bw=/dev/sda:5M']);

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            "user=testuser uid=1000 slice=user-1000.slice mode=v2\n"
            ."[Planned IO properties]\n"
            ."IOReadBandwidthMax=/dev/sda 5M\n"
            ."(dry-run or no --apply; not changing system)\n",
            $res['out']
        );
    }

    public function testIoProfilePolicyOverridesPreserveBuiltInFallbacks()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, [
            'profiles' => [
                'io' => [
                    'hdd' => ['ioWeight' => 777, 'writeBw' => '12M'],
                ],
            ],
        ], 0700);
        $this->sys->findmnt['/dev/sdb'] = '/dev/sdb';

        $this->assertRunOutput(
            array('testuser', '--device=/dev/sdb', '--io-profile=hdd'),
            array('IOWeight=777', 'IOReadBandwidthMax=/dev/sdb 5M', 'IOWriteBandwidthMax=/dev/sdb 12M')
        );
    }

    // -- Defaults & Policy Tests --

    public function testDefaultsApplication()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, ['cpuWeight' => 500, 'tasksMax' => 2048], 0700);
        $this->assertRunOutput(array('testuser', '--defaults'), array('CPUWeight=500', 'TasksMax=2048'));
    }

    public function testCliOverridesDefaults()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, ['cpuWeight' => 500], 0700);
        $this->assertRunOutput(array('testuser', '--defaults', '--cpu-weight=100'), array('CPUWeight=100'), array('CPUWeight=500'));
    }

    public function testRespectExisting()
    {
        $this->pmssWriteCgroupPolicyFixture($this->configDir, ['cpuWeight' => 500], 0700);
        $this->sys->commands['systemctl show'] = "CPUWeight=123\n";
        
        // Should NOT apply 500 because 123 exists
        $this->assertRunOutput(array('testuser', '--defaults', '--respect-existing'), array(), array('CPUWeight=500'));
    }

    // -- Execution & Command Generation --

    public function testApplyGeneratesCommand()
    {
        // Use --dry-run to avoid real systemctl calls and assert that the
        // planned properties reflect the requested change.
        $this->assertRunOutput(array('testuser', '--apply', '--dry-run', '--cpu-weight=400'), array('CPUWeight=400'));
    }

    public function testApplyReturnsFailureWhenCgroupStepFails()
    {
        $steps = [];
        $this->mgr = new Manager($this->sys, function (string $description, string $command) use (&$steps): int {
            $steps[] = [$description, $command];
            return 7;
        });

        $res = $this->runMgr(['testuser', '--apply', '--cpu-weight=400']);

        $this->assertEquals(1, $res['rc']);
        $this->assertEquals('Applying cgroup properties', $steps[0][0]);
        $this->assertStringContainsString('systemctl', $steps[0][1]);
    }

    public function testApplyStepSnapshotLocksMixedPropertiesBeforeIoCostWrites(): void
    {
        $steps = [];
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";
        $this->mgr = new Manager($this->sys, function (string $description, string $command) use (&$steps): int {
            $steps[] = [$description, $command];
            return 0;
        });

        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--memory-high=600',
            '--io-cost-qos=enable=1 ctrl=user',
        ]);

        $ioCostWriter = "if [ -w '/sys/fs/cgroup/io.cost.qos' ]; then printf '%s\\n' "
            ."'9:0 enable=1 ctrl=user' > '/sys/fs/cgroup/io.cost.qos'; else echo "
            ."'[ERR] io.cost path not writable: /sys/fs/cgroup/io.cost.qos'; exit 1; fi";

        $this->assertEquals(0, $res['rc']);
        $this->assertSame(
            [
                [
                    'Applying cgroup properties',
                    \pmssBuildCommand('systemctl', [
                        'set-property',
                        'user-1000.slice',
                        'MemoryHigh=600M',
                        'MemoryMax=750M',
                        'CPUWeight=196',
                        'IOWeight=196',
                    ]),
                ],
                ['Applying io.cost setting', \pmssBuildCommand('sh', ['-c', $ioCostWriter])],
            ],
            $steps
        );
    }

    public function testApplyAttemptsEveryIoCostWriteBeforeReturningFailure()
    {
        $steps = [];
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";
        $this->sys->files['/sys/fs/cgroup/user.slice/user-1000.slice/io.cost.qos'] = '';
        $this->sys->files['/sys/fs/cgroup/user.slice/user-1000.slice/io.cost.model'] = '';
        $this->mgr = new Manager($this->sys, function (string $description, string $command) use (&$steps): int {
            $steps[] = [$description, $command];
            return count($steps) === 1 ? 5 : 0;
        });

        $res = $this->runMgr([
            'testuser',
            '--apply',
            '--io-cost-qos=enable=1 ctrl=user',
            '--io-cost-model=ctrl=user model=linear',
        ]);

        $this->assertEquals(1, $res['rc']);
        $this->assertEquals(
            4,
            count($steps),
            'all planned io.cost writes should be attempted for operator visibility'
        );
        foreach ($steps as $step) {
            $this->assertEquals('Applying io.cost setting', $step[0]);
        }
    }

    public function testApplyRefusesRootSliceMutation()
    {
        $this->ensureManager();
        $this->sys->users['root'] = 0;

        $res = $this->runMgr(['root', '--apply', '--cpu-weight=400']);

        $this->assertEquals(1, $res['rc']);
        $this->assertTrue(empty($this->sys->executedCommands), 'root apply must return before system command execution');
    }

    public function testWipeCommand()
    {
        // In dry-run mode wipe should plan work but not execute systemctl.
        $this->assertRunOutput(
            array('testuser', '--apply', '--dry-run', '--wipe'),
            array('(dry-run or no --apply; not changing system)')
        );
    }

    public function testWipeRejectsMixedResourceOptions()
    {
        $this->assertRunOutput(
            array('testuser', '--apply', '--dry-run', '--wipe', '--memory-high=600'),
            array(),
            array('(dry-run or no --apply; not changing system)'),
            2
        );
    }

    public function testWipeRejectsMixedIoCostOptions()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $this->assertRunOutput(
            array('testuser', '--apply', '--dry-run', '--wipe', '--io-cost-qos=enable=1 ctrl=user'),
            array(),
            array('[Planned io.cost writes]'),
            2
        );
    }

    public function testWipeRejectsDefaultPolicyInputs(): void
    {
        foreach ([
            ['--defaults'],
            ['--respect-existing'],
            ['--defaults', '--respect-existing'],
        ] as $case) {
            $this->assertRunOutput(
                array_merge(['testuser', '--apply', '--dry-run', '--wipe'], $case),
                array(),
                array('(dry-run or no --apply; not changing system)'),
                2
            );
        }
    }

    // -- Edge Cases & Adversarial --

    public function testUserShellInjection()
    {
        // PHP escapeshellarg handles this; we ensure the manager accepts the
        // username without throwing and returns a non-fatal status.
        $user = 'user;rm -rf /';
        $this->sys->users[$user] = 9999; // Mock existence
        $this->assertRunOutput(array($user, '--apply', '--dry-run', '--cpu-weight=100'));
    }

    public function testNegativeWeights()
    {
        // Logic casts to int. systemctl might reject, but script just passes it.
        // We assume garbage in -> garbage out to systemctl, unless we want validation.
        // Code currently casts to (int).
        $this->assertRunOutput(array('testuser', '--cpu-weight=-50'), array('CPUWeight=-50'));
    }

    public function testZeroMemory()
    {
        $this->sys->totalMemMiB = 0;
        // MaxCap is 0 or PHP_INT_MAX?
        // $maxCap = $sysMemMiB > 0 ? ... : PHP_INT_MAX;
        // So it should fallback to High * 1.25 (125) or clamped to High+2GB.
        // High 100 -> 250 floor. Max 312.
        $this->assertRunOutput(array('testuser', '--memory-high=100'), array('MemoryMax=312M'));
    }
}
