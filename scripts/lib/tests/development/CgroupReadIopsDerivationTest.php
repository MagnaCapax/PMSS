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
    }

    public function testFloorSumAboveMeasuredHostMaxLogsAndSkipsDerivation(): void
    {
        $messages = [];
        $allowed = \pmssCgroupPolicyReadIopsDerivationAllowed(
            300.0,
            200,
            2,
            static function (string $message) use (&$messages): void { $messages[] = $message; }
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
