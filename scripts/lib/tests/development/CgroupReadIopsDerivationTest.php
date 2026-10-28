<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/policy.php';
require_once __DIR__.'/../../cgroup/ioCeilingHistory.php';

/**
 * Hermetic coverage for transient per-user read-IOPS derivation.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
class CgroupReadIopsDerivationTest extends TestCase
{
    private function policy(array $extra = []): array
    {
        return array_replace_recursive([
            'ioWeight' => 100,
            'cgroup' => [
                'assignMax' => ['iops' => 2.0],
                'readIopsClassFloors' => ['default' => 200, 'hdd' => 200, 'ssd' => 500, 'nvme' => 750],
            ],
        ], $extra);
    }

    public function testClampBoundaryCases(): void
    {
        $this->assertSame(200, \pmssCgroupPolicyReadIopsClamp(50, 200, 1000));
        $this->assertSame(600, \pmssCgroupPolicyReadIopsClamp(600, 200, 1000));
        $this->assertSame(1000, \pmssCgroupPolicyReadIopsClamp(1500, 200, 1000));
        $this->assertSame(150, \pmssCgroupPolicyReadIopsClamp(50, 200, 150), 'absolute tier ceiling wins over the floor');
    }

    /** Characterize the legacy coercions and precedence; refactoring must not tighten them. */
    public function testAccountPolicyResolutionCharacterization(): void
    {
        foreach ([
            [[], [], 100], [['IOWeight' => '007'], ['ioWeight' => 500], 7],
            // PHP 8 broadened is_numeric() to accept trailing whitespace.
            [['IOWeight' => ' 007 '], ['ioWeight' => 500], PHP_VERSION_ID >= 80000 ? 7 : 500],
            [['IOWeight' => '3.9'], [], 3], [['IOWeight' => 0], ['ioWeight' => '250'], 250],
            [['IOWeight' => []], ['ioWeight' => false], 100],
            [['IOWeight' => -1], ['ioWeight' => null], 100],
        ] as [$source, $policy, $expected]) {
            $this->assertSame($expected, \pmssCgroupPolicyUserIoWeight($source, $policy));
        }
        $policy = ['serviceTypes' => ['plan' => ['resourceIOPSReadMax' => '900'], 'bad' => 'invalid']];
        foreach ([
            [[], null], [['resourceIOPSReadMax' => '50', 'serviceType' => 'plan'], 50],
            [['resourceIOPSReadMax' => 0, 'serviceType' => ' plan '], 900],
            [['serviceType' => ['resourceIOPSReadMax' => '75.9']], 75],
            [['serviceType' => ['resourceIOPSReadMax' => -1]], null],
            [['serviceType' => []], null], [['serviceType' => 'bad'], null],
            [['serviceType' => 'missing'], null], [['serviceType' => null], null],
        ] as [$source, $expected]) {
            $this->assertSame($expected, \pmssCgroupPolicyReadIopsTierCeiling($source, $policy));
        }
        $this->assertSame(['default' => 200, 'storage' => 200, 'hdd' => 200, 'ssd' => 900, 'nvme' => 900],
            \pmssCgroupPolicyReadIopsClassFloors(['cgroup' => ['readIopsClassFloors' =>
                ['SSD' => 900, 'nvme' => 400, 'hdd' => 0, '' => 5, 0 => 5]]]));
    }

    public function testExplicitReadIopsIsNeverOverwrittenByDerivation(): void
    {
        $payload = ['IOReadIOPS' => '/home:777', 'IOWeight' => 300, 'resourceIOPSReadMax' => 2000];

        $this->assertSame(null, \pmssCgroupPolicyDerivedReadIopsCap(
            $payload,
            $this->policy(),
            3000.0,
            300,
            1,
            'hdd',
            true
        ));
        $this->assertSame(200, \pmssCgroupPolicyDerivedReadIopsCap(['IOReadIOPS' => []], $this->policy(), null, 100, 1, 'hdd'));
    }

    public function testMissingOrInsufficientTrackerFallsBackToClassFloorOnly(): void
    {
        $missing = $this->pmssMakeTempPath('pmss-missing-io-ceiling-', '.json');
        $payload = ['IOWeight' => 100, 'resourceIOPSReadMax' => 2000];

        $this->assertSame(null, \pmssIoCeilingPublishedReadIops($missing));
        $this->assertSame(500, \pmssCgroupPolicyDerivedReadIopsCap(
            $payload,
            $this->policy(),
            \pmssIoCeilingPublishedReadIops($missing),
            100,
            1,
            'ssd',
            true
        ));

        $thin = $this->pmssWriteTempFile('pmss-thin-io-ceiling-', '');
        file_put_contents($thin, json_encode([
            'min_days' => 2,
            'min_samples_per_day' => 144,
            'days' => ['2026-09-20' => ['samples' => 144]],
            'published' => ['read_iops' => 9000],
        ]));
        $this->assertSame(null, \pmssIoCeilingPublishedReadIops($thin));
    }

    public function testDerivedCapUsesWeightShareOversellFloorAndTierCeiling(): void
    {
        $policy = $this->policy();
        $payload = ['IOWeight' => 100, 'resourceIOPSReadMax' => 900];

        $this->assertSame(500, \pmssCgroupPolicyDerivedReadIopsCap($payload, $policy, 1000.0, 400, 4, 'ssd', true));
        $this->assertSame(900, \pmssCgroupPolicyDerivedReadIopsCap($payload, $policy, 5000.0, 400, 4, 'ssd', true));
        $this->assertSame(500, \pmssCgroupPolicyDerivedReadIopsCap($payload, $this->policy(['cgroup' => ['assignMax' => ['iops' => 1.0]]]), 1000.0, 400, 4, 'ssd'));
        $this->assertSame(900, \pmssCgroupPolicyDerivedReadIopsCap($payload, $this->policy(['cgroup' => ['assignMax' => ['iops' => 0]]]), 2000.0, 400, 4, 'ssd'));
    }

    public function testFloorSumAboveMeasuredHostMaxLogsAndSkipsDerivation(): void
    {
        $messages = [];
        $allowed = \pmssCgroupPolicyReadIopsDerivationAllowed(
            300.0,
            200,
            2,
            $this->pmssMakeArrayLogger($messages)
        );

        $this->assertFalse($allowed);
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('class floor sum 400 exceeds host max 300', $messages[0]);
        $this->assertSame(null, \pmssCgroupPolicyDerivedReadIopsCap(
            ['IOWeight' => 100, 'resourceIOPSReadMax' => 2000],
            $this->policy(),
            300.0,
            200,
            2,
            'hdd',
            $allowed
        ));
    }

    public function testMdBackedHomeIsDetectedSoCallersCanLeaveDerivedCapsUnset(): void
    {
        $root = $this->pmssMakeTempDir('pmss-read-iops-class-', 0700);
        @mkdir($root.'/md1/md', 0700, true);
        @mkdir($root.'/sda/queue', 0700, true);
        file_put_contents($root.'/sda/queue/rotational', "1\n");

        $this->assertTrue(\pmssCgroupPolicyHomeDeviceIsMdBacked('/dev/md1', $root));
        $this->assertSame('hdd', \pmssCgroupPolicyHomeStorageClassResolve('/dev/sda', $root));
    }

    /** Lock storage-class precedence across direct disks and stacked devices. */
    public function testHomeStorageClassPreservesDirectAndStackedPrecedence(): void
    {
        $root = $this->pmssMakeTempDir('pmss-read-iops-topology-', 0700);
        foreach (['md0', 'md1', 'sda', 'sdb', 'nvme0n1', 'dm0'] as $device) {
            @mkdir($root.'/'.$device.'/queue', 0700, true);
            @mkdir($root.'/'.$device.'/slaves', 0700, true);
        }
        file_put_contents($root.'/sda/queue/rotational', "1\n");
        file_put_contents($root.'/sdb/queue/rotational', "0\n");
        file_put_contents($root.'/dm0/queue/rotational', "0\n");
        file_put_contents($root.'/md1/queue/rotational', "1\n");
        symlink($root.'/sdb', $root.'/md0/slaves/sdb');
        symlink($root.'/sda', $root.'/md0/slaves/sda');
        symlink($root.'/nvme0n1', $root.'/dm0/slaves/nvme0n1');
        symlink($root.'/sdb', $root.'/md1/slaves/sdb');

        foreach (['sda' => 'hdd', 'sdb' => 'ssd', 'nvme0n1' => 'nvme',
            'md0' => 'hdd', 'md1' => 'hdd', 'dm0' => 'nvme', 'missing' => null] as $device => $class) {
            $this->assertSame($class, \pmssCgroupPolicyHomeStorageClassResolve('/dev/'.$device, $root), $device);
        }
        $this->assertSame(null, \pmssCgroupPolicyHomeStorageClassResolve('/dev/bad name', $root));
    }

    /** Malformed source bytes cannot be normalized into a device observation. */
    public function testStorageObservationRejectsMalformedDevicePaths(): void
    {
        $root = $this->pmssMakeTempDir('pmss-read-iops-paths-', 0700);
        // A root queue would otherwise let dot components masquerade as a disk.
        @mkdir($root.'/queue', 0700, true);
        @mkdir($root.'/md', 0700, true);
        file_put_contents($root.'/queue/rotational', "1\n");
        foreach (["\0/dev/sda", "/dev/sda\0", "/bad\0/dev/sda", "/dev/md0\0",
            "/dev/nvme0n1\0", '/dev/.', '/dev/..', '', '/dev/bad name'] as $path) {
            $this->assertSame(null, \pmssCgroupPolicyBlockDeviceName($path));
            $this->assertFalse(\pmssCgroupPolicyHomeDeviceIsMdBacked($path, $root));
            $this->assertSame(null, \pmssCgroupPolicyHomeStorageClassResolve($path, $root));
        }
    }

    /** Reject corrupted sysfs roots even when a device name alone implies its class. */
    public function testStorageObservationRejectsNulSysfsRoots(): void
    {
        foreach (["\0", "/sys/class/block\0", "\0/sys/class/block", "/sys/\0/class/block"] as $root) {
            foreach (['/dev/sda', '/dev/md0', '/dev/nvme0n1', '/dev/dm-0', '/dev/missing'] as $path) {
                $this->assertFalse(\pmssCgroupPolicyHomeDeviceIsMdBacked($path, $root));
                $this->assertSame(null, \pmssCgroupPolicyHomeStorageClassResolve($path, $root));
            }
        }
    }

    /** Preserve valid basename extraction, surrounding whitespace and device punctuation. */
    public function testStorageObservationPreservesValidDeviceNames(): void
    {
        foreach (['/dev/sda' => 'sda', " \t/dev/md0\n" => 'md0', '/dev/nvme0n1p2' => 'nvme0n1p2',
            'sda' => 'sda', '/dev/dm-0' => 'dm-0', '/dev/disk.name' => 'disk.name',
            '/dev/cciss!c0d0' => 'cciss!c0d0', '/dev/device+1' => 'device+1'] as $path => $name) {
            $this->assertSame($name, \pmssCgroupPolicyBlockDeviceName($path));
        }
    }

    public function testUnknownClassUsesProfileDefaultFloor(): void
    {
        $this->assertSame(200, \pmssCgroupPolicyDerivedReadIopsCap(
            ['IOWeight' => 100, 'resourceIOPSReadMax' => 2000],
            $this->policy(),
            null,
            100,
            1,
            null,
            true
        ));
    }

    public function testDirectEnforcerDerivesReadOnlyWithoutUserConfigPersistence(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/cgroupIopsLimitApply.php', [
            '$readIops  = pmssIopsParseSpec($json[\'IOReadIOPS\']  ?? null);',
            'if ($readIops === null) {',
            '$readIops = pmssCgroupPolicyDerivedReadIopsCap(',
            '$limits = [$readIops, $writeIops];',
            "['read', \$limits[0], pmssCgroupDirectUserBlkioFilePath(\$uid, 'blkio.throttle.read_iops_device')]",
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/cron/cgroupIopsLimitApply.php', [
            'UserConfigStore',
            'pmssAtomicWriteFile',
            'writeJsonFileAtomic',
            '$writeIops = pmssCgroupPolicyDerivedReadIopsCap(',
        ]);
    }
}
