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

    /**
     * Ensure the manager/system fixtures are initialised for the current test.
     *
     * Some harnesses may bypass setUp(); this helper keeps the tests robust
     * by lazily constructing MockSystem/Manager when needed.
     */
    private function ensureManager(): void
    {
        if ($this->sys === null || $this->mgr === null) {
            $this->sys = new MockSystem();
            $this->mgr = new Manager($this->sys);
            putenv('PMSS_CONFIG_DIR=' . sys_get_temp_dir());
        }
    }

    protected function setUp(): void
    {
        $this->sys = new MockSystem();
        $this->mgr = new Manager($this->sys);
        putenv('PMSS_CONFIG_DIR=' . sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $this->sys = null;
        $this->mgr = null;
        @unlink(sys_get_temp_dir().'/cgroup.policy.php');
    }

    private function runMgr(array $args)
    {
        $this->ensureManager();
        // Prepend dummy script name
        array_unshift($args, 'userConfigCgroup.php');
        list($rc, $out) = $this->pmssCaptureStdout(function () use ($args) { return $this->mgr->run($args); });
        return ['rc' => $rc, 'out' => $out];
    }

    public function testUsage()
    {
        $res = $this->runMgr([]);
        // Manager prints usage to STDERR; we only assert on the return code
        // here because stdout capture does not include STDERR in the harness.
        $this->assertEquals(2, $res['rc']);
    }

    public function testUnknownUser()
    {
        $res = $this->runMgr(['ghost']);
        $this->assertEquals(1, $res['rc']);
    }

    public function testValidUserStatus()
    {
        $res = $this->runMgr(['testuser']);
        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('slice=user-1000.slice', $res['out']);
        $this->assertStringContainsString('[Config]', $res['out']);
        $this->assertStringContainsString('[Status]', $res['out']);
    }

    // -- Memory Calculation & Clamping Tests --

    public function testMemoryDefaultCalculation()
    {
        // 16GB RAM. Default High = 10% = 1638M. Max = 1.25x = 2047M.
        // No apply, just check planned output.
        $res = $this->runMgr(['testuser', '--defaults']); 
        // Note: --defaults reads policy. If no policy, no defaults applied?
        // Wait, computeSetProps calculates defaults if flags are present OR if logic requires it.
        // Actually, computeSetProps only runs if $opt is not empty or if we pass something.
        // The script says: "Compute final properties ... $props = !empty($opt) ? ... : []"
        // So without flags or policy, no calculation happens.
        // Let's force calculation by passing --memory-high without value? No, parser requires value.
        // Let's create a policy file.
        $policy = '<?php return ["memoryHighMiB"=>1000];';
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        
        $res = $this->runMgr(['testuser', '--defaults']);
        $this->assertStringContainsString('MemoryHigh=1000M', $res['out']);
        // Max should be derived: 1000 * 1.25 = 1250
        $this->assertStringContainsString('MemoryMax=1250M', $res['out']);
    }

    public function testMemoryClampEnforcement()
    {
        // 16GB RAM. Explicit High 2000. Explicit Max 10000.
        // Clamp: Max <= High + 2048 = 4048.
        $res = $this->runMgr(['testuser', '--memory-high=2000', '--memory-max=10000']);
        $this->assertStringContainsString('MemoryHigh=2000M', $res['out']);
        $this->assertStringContainsString('MemoryMax=4048M', $res['out']);
    }

    public function testMemoryClampWithSmallValues()
    {
        // High 500. Max 1000. 1000 < 500+2048. OK.
        $res = $this->runMgr(['testuser', '--memory-high=500', '--memory-max=1000']);
        $this->assertStringContainsString('MemoryHigh=500M', $res['out']);
        $this->assertStringContainsString('MemoryMax=1000M', $res['out']);
    }

    public function testMemoryFloor()
    {
        // High 100 (below 250 floor).
        $res = $this->runMgr(['testuser', '--memory-high=100']);
        $this->assertStringContainsString('MemoryHigh=250M', $res['out']);
        // Max derived from clamped high: 250 * 1.25 = 312
        $this->assertStringContainsString('MemoryMax=312M', $res['out']);
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

    public function testScalarOptionParserKeepsInlineOnlyContract(): void
    {
        $split = $this->runMgr(['testuser', '--memory-high', '600']);

        $this->assertEquals(0, $split['rc']);
        $this->assertStringNotContainsString('[Planned properties]', $split['out']);
        $this->assertStringContainsString('[Config]', $split['out']);

        $bareAfterInline = $this->runMgr(['testuser', '--memory-high=600', '--memory-high']);
        $this->assertEquals(0, $bareAfterInline['rc']);
        $this->assertStringContainsString('MemoryHigh=600M', $bareAfterInline['out']);
    }

    public function testMemory95PercentCap()
    {
        // 1GB System RAM. Cap ~972M.
        $this->sys->totalMemMiB = 1024;
        // Request High 900. Max 2000.
        // High 900 OK.
        // Max cap at 972.
        $res = $this->runMgr(['testuser', '--memory-high=900', '--memory-max=2000']);
        $this->assertStringContainsString('MemoryHigh=900M', $res['out']);
        // Max should be capped by system RAM (~972) not 900+2048
        $this->assertStringContainsString('MemoryMax=972M', $res['out']);
    }

    // -- CPU Quota Tests --

    public function testCpuQuotaInfinity()
    {
        $res = $this->runMgr(['testuser', '--cpu-quota-percent=infinity']);
        // Should result in empty string
        $this->assertStringContainsString('CPUQuota=', $res['out']);
        $this->assertStringNotContainsString('CPUQuota=infinity', $res['out']);
    }

    public function testCpuQuotaZero()
    {
        $res = $this->runMgr(['testuser', '--cpu-quota-percent=0']);
        $this->assertStringContainsString('CPUQuota=', $res['out']);
        $this->assertStringNotContainsString('CPUQuota=0', $res['out']);
    }

    public function testCpuQuotaValue()
    {
        $res = $this->runMgr(['testuser', '--cpu-quota-percent=200']);
        $this->assertStringContainsString('CPUQuota=200%', $res['out']);
    }

    public function testRejectsInvalidCpuWeightValue()
    {
        $res = $this->runMgr(['testuser', '--cpu-weight=abc']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testRejectsDecimalMemoryHighValue()
    {
        $res = $this->runMgr(['testuser', '--memory-high=12.5']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testRejectsInvalidCpuQuotaValue()
    {
        $res = $this->runMgr(['testuser', '--cpu-quota-percent=fast']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testRejectsMalformedIoBandwidthSpec()
    {
        $res = $this->runMgr(['testuser', '--io-read-bw=/dev/sda']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testRejectsRelativeIoBandwidthDeviceSpec()
    {
        $res = $this->runMgr(['testuser', '--io-read-bw=tmp/device:5M']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testRejectsNonDeviceIoBandwidthPathSpec()
    {
        $res = $this->runMgr(['testuser', '--io-read-bw=/tmp/device:5M']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testRejectsWhitespaceInDeviceValue()
    {
        $res = $this->runMgr(['testuser', '--device=/dev/sda bad', '--io-profile=hdd']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testApplyBuildsShellSafeIoPropertyArguments()
    {
        putenv('PMSS_DRY_RUN=1');
        try {
            $res = $this->runMgr(['testuser', '--apply', '--io-read-bw=/dev/sda:5M']);
        } finally {
            putenv('PMSS_DRY_RUN');
        }

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString("'IOReadBandwidthMax=/dev/sda 5M'", $res['out']);
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

    public function testIoLatencyDefaultsToHomeBackingDevice()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr(['testuser', '--io-latency-ms=50']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('IODeviceLatencyTargetSec=/dev/md0 50ms', $res['out']);
    }

    public function testIoLatencyRequiresPositiveInteger()
    {
        $res = $this->runMgr(['testuser', '--io-latency-ms=0']);
        $this->assertEquals(2, $res['rc']);
    }

    public function testDefaultsApplyPolicyLatencyToHomeDevice()
    {
        $policy = '<?php return ["ioLatencyMs"=>45];';
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr(['testuser', '--defaults']);

        $this->assertStringContainsString('IODeviceLatencyTargetSec=/dev/md0 45ms', $res['out']);
    }

    public function testIoLatencySkippedOnCgroupV1()
    {
        $this->sys->cgroupMode = 'v1';
        $res = $this->runMgr(['testuser', '--io-latency-ms=50']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('IODeviceLatencyTargetSec requires cgroup v2', $res['out']);
        $this->assertStringNotContainsString('IODeviceLatencyTargetSec=/dev/', $res['out']);
    }

    public function testIoCostQosDefaultsToHomeDeviceMajorMinor()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $res = $this->runMgr(['testuser', '--io-cost-qos=enable=1 ctrl=user']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('[Planned io.cost writes]', $res['out']);
        $this->assertStringContainsString('/sys/fs/cgroup/io.cost.qos <= 9:0 enable=1 ctrl=user', $res['out']);
    }

    public function testIoCostAcceptsMatchingExplicitMajorMinor()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $res = $this->runMgr(['testuser', '--io-cost-qos=9:0 enable=1 ctrl=user']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('[Planned io.cost writes]', $res['out']);
        $this->assertStringContainsString('/sys/fs/cgroup/io.cost.qos <= 9:0 enable=1 ctrl=user', $res['out']);
    }

    public function testIoCostRejectsMismatchedExplicitMajorMinor()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $res = $this->runMgr(['testuser', '--io-cost-qos=8:0 enable=1 ctrl=user']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('io.cost skipped: invalid io.cost.qos setting', $res['out']);
        $this->assertStringNotContainsString('[Planned io.cost writes]', $res['out']);
    }

    public function testIoCostSkippedWhenBfqSchedulerActive()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";
        $this->sys->commands['grep -l'] = "/sys/class/block/sda/queue/scheduler\n";

        $res = $this->runMgr(['testuser', '--io-cost-qos=enable=1 ctrl=user']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('io.cost skipped: BFQ scheduler active', $res['out']);
        $this->assertStringNotContainsString('[Planned io.cost writes]', $res['out']);
    }

    public function testIoCostSkippedOnCgroupV1()
    {
        $this->sys->cgroupMode = 'v1';
        $res = $this->runMgr(['testuser', '--io-cost-qos=enable=1 ctrl=user']);

        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('io.cost requires cgroup v2', $res['out']);
    }

    public function testIoCostRejectsControlCharacters()
    {
        foreach (['--io-cost-qos', '--io-cost-model'] as $flag) {
            $res = $this->runMgr(['testuser', $flag."=enable=1\nctrl=user"]);
            $this->assertEquals(2, $res['rc']);
        }
    }

    // -- Profile Tests --

    public function testCpuProfileLow()
    {
        $res = $this->runMgr(['testuser', '--cpu-profile=low']);
        $this->assertStringContainsString('CPUWeight=50', $res['out']);
    }

    public function testCpuProfileHigh()
    {
        $res = $this->runMgr(['testuser', '--cpu-profile=high']);
        $this->assertStringContainsString('CPUWeight=300', $res['out']);
    }

    public function testMemProfileLow()
    {
        $res = $this->runMgr(['testuser', '--mem-profile=low']);
        $this->assertStringContainsString('MemoryHigh=250M', $res['out']);
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
        $res = $this->runMgr(['testuser', '--device=/dev/sdb', '--io-profile=bulk']);
        $this->assertStringContainsString('IOWeight=500', $res['out']);
        $this->assertStringContainsString('CPUWeight=300', $res['out']);
        $this->assertStringContainsString('TasksMax=8192', $res['out']);
    }

    public function testDefaultsApplyPolicyMountIoPairsWithoutExplicitIoInput()
    {
        $policy = <<<'PHP'
<?php return [
    'mounts' => [
        '/home' => [
            'ioWeight' => 333,
            'readBw' => '6M',
            'readIops' => 123,
        ],
    ],
];
PHP;
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        $this->sys->findmnt['/home'] = '/dev/md0';

        $res = $this->runMgr(['testuser', '--defaults']);

        $this->assertStringContainsString('IODeviceWeight=/dev/md0 333', $res['out']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/md0 6M', $res['out']);
        $this->assertStringContainsString('IOReadIOPSMax=/dev/md0 123', $res['out']);
    }

    public function testDefaultsSkipPolicyMountIoPairsWhenExplicitIoInputIsPresent(): void
    {
        $policy = <<<'PHP'
<?php return [
    'mounts' => [
        '/home' => [
            'ioWeight' => 333,
            'readBw' => '6M',
            'readIops' => 123,
        ],
    ],
];
PHP;
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
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
        $policy = <<<'PHP'
<?php return [
    'profiles' => [
        'io' => [
            'hdd' => [
                'ioWeight' => 777,
                'writeBw' => '12M',
            ],
        ],
    ],
];
PHP;
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        $this->sys->findmnt['/dev/sdb'] = '/dev/sdb';

        $res = $this->runMgr(['testuser', '--device=/dev/sdb', '--io-profile=hdd']);

        $this->assertStringContainsString('IOWeight=777', $res['out']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sdb 5M', $res['out']);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/sdb 12M', $res['out']);
    }

    // -- Defaults & Policy Tests --

    public function testDefaultsApplication()
    {
        $policy = '<?php return ["cpuWeight"=>500, "tasksMax"=>2048];';
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        $res = $this->runMgr(['testuser', '--defaults']);
        $this->assertStringContainsString('CPUWeight=500', $res['out']);
        $this->assertStringContainsString('TasksMax=2048', $res['out']);
    }

    public function testCliOverridesDefaults()
    {
        $policy = '<?php return ["cpuWeight"=>500];';
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        $res = $this->runMgr(['testuser', '--defaults', '--cpu-weight=100']);
        $this->assertStringContainsString('CPUWeight=100', $res['out']);
        $this->assertStringNotContainsString('CPUWeight=500', $res['out']);
    }

    public function testRespectExisting()
    {
        $policy = '<?php return ["cpuWeight"=>500];';
        file_put_contents(sys_get_temp_dir().'/cgroup.policy.php', $policy);
        // Mock existing property
        $this->sys->commands['systemctl show'] = "CPUWeight=123\n"; // simplified mock response
        // Note: The mock execute logic needs to match the specific command string used in readCurrentProps
        // Command: systemctl show 'user-1000.slice' -p CPUWeight -p ...
        // The mock looks for substring match.
        $this->sys->commands['systemctl show'] = "CPUWeight=123\n"; 
        
        $res = $this->runMgr(['testuser', '--defaults', '--respect-existing']);
        // Should NOT apply 500 because 123 exists
        $this->assertStringNotContainsString('CPUWeight=500', $res['out']);
    }

    // -- Execution & Command Generation --

    public function testApplyGeneratesCommand()
    {
        // Use --dry-run to avoid real systemctl calls and assert that the
        // planned properties reflect the requested change.
        $res = $this->runMgr(['testuser', '--apply', '--dry-run', '--cpu-weight=400']);
        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('CPUWeight=400', $res['out']);
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
            ."'[SKIP] io.cost path not writable: /sys/fs/cgroup/io.cost.qos'; fi";

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
        $res = $this->runMgr(['testuser', '--apply', '--dry-run', '--wipe']);
        $this->assertEquals(0, $res['rc']);
        $this->assertStringContainsString('(dry-run or no --apply; not changing system)', $res['out']);
    }

    public function testWipeRejectsMixedResourceOptions()
    {
        $res = $this->runMgr(['testuser', '--apply', '--dry-run', '--wipe', '--memory-high=600']);

        $this->assertEquals(2, $res['rc']);
        $this->assertStringNotContainsString('(dry-run or no --apply; not changing system)', $res['out']);
    }

    public function testWipeRejectsMixedIoCostOptions()
    {
        $this->sys->findmnt['/home'] = '/dev/md0';
        $this->sys->commands['lsblk -dn -o MAJ:MIN'] = "9:0\n";

        $res = $this->runMgr(['testuser', '--apply', '--dry-run', '--wipe', '--io-cost-qos=enable=1 ctrl=user']);

        $this->assertEquals(2, $res['rc']);
        $this->assertStringNotContainsString('[Planned io.cost writes]', $res['out']);
    }

    public function testWipeRejectsDefaultPolicyInputs(): void
    {
        foreach ([
            ['--defaults'],
            ['--respect-existing'],
            ['--defaults', '--respect-existing'],
        ] as $case) {
            $res = $this->runMgr(array_merge(['testuser', '--apply', '--dry-run', '--wipe'], $case));

            $this->assertEquals(2, $res['rc']);
            $this->assertStringNotContainsString('(dry-run or no --apply; not changing system)', $res['out']);
        }
    }

    // -- Edge Cases & Adversarial --

    public function testUserShellInjection()
    {
        // PHP escapeshellarg handles this; we ensure the manager accepts the
        // username without throwing and returns a non-fatal status.
        $user = 'user;rm -rf /';
        $this->sys->users[$user] = 9999; // Mock existence
        $res = $this->runMgr([$user, '--apply', '--dry-run', '--cpu-weight=100']);
        $this->assertEquals(0, $res['rc']);
    }

    public function testNegativeWeights()
    {
        // Logic casts to int. systemctl might reject, but script just passes it.
        // We assume garbage in -> garbage out to systemctl, unless we want validation.
        // Code currently casts to (int).
        $res = $this->runMgr(['testuser', '--cpu-weight=-50']);
        $this->assertStringContainsString('CPUWeight=-50', $res['out']);
    }

    public function testZeroMemory()
    {
        $this->sys->totalMemMiB = 0;
        $res = $this->runMgr(['testuser', '--memory-high=100']);
        // MaxCap is 0 or PHP_INT_MAX?
        // $maxCap = $sysMemMiB > 0 ? ... : PHP_INT_MAX;
        // So it should fallback to High * 1.25 (125) or clamped to High+2GB.
        // High 100 -> 250 floor. Max 312.
        $this->assertStringContainsString('MemoryMax=312M', $res['out']);
    }
}
