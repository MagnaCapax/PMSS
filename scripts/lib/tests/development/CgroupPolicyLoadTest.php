<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/services/ioPlacement.php';

/** Hermetic coverage for persistent host overrides and placement convergence. */
final class CgroupPolicyLoadTest extends TestCase
{
    public function testMissingLocalPolicyPreservesTheShippedPolicy(): void
    {
        $policy = ['ioWeight' => 100, 'cgroup' => ['ioSlicePlacement' => false]];
        $directory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        $this->assertSame($policy, \pmssCgroupPolicyLoad($directory));
        $this->assertSame([], \pmssCgroupPolicyLoad($directory.'/missing'));
    }

    public function testNestedOverridesRetainOtherDefaultsAndUseTheConfiguredDirectory(): void
    {
        $policy = ['ioWeight' => 100, 'cgroup' => ['ioSlicePlacement' => false,
            'dynamicIops' => ['mode' => 'off', 'windowSeconds' => 300]]];
        $directory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        $local = ['cgroup' => ['ioSlicePlacement' => true, 'dynamicIops' => ['mode' => 'shadow']]];
        $this->pmssWriteFile($directory.'/cgroup.policy.local.php', $this->pmssCgroupPolicySource($local));
        $policy['cgroup']['ioSlicePlacement'] = true;
        $policy['cgroup']['dynamicIops']['mode'] = 'shadow';
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $directory], function () use ($policy): void {
            $this->assertSame($policy, \pmssCgroupPolicyLoad());
        });
        $this->assertSame($policy, \pmssCgroupPolicyLoad($directory.'/'));
    }

    public function testNonArrayLocalPoliciesLeaveDefaultsIntact(): void
    {
        $policy = ['cgroup' => ['ioSlicePlacement' => false]];
        $directory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        foreach ([null, false, true, 1, 'enabled'] as $value) {
            $this->pmssWriteFile($directory.'/cgroup.policy.local.php', '<?php return '.var_export($value, true).';');
            $this->assertSame($policy, \pmssCgroupPolicyLoad($directory));
        }
    }

    public function testLocalPolicyCanLoadWithoutValidShippedDefaults(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-local-policy-');
        $local = ['cgroup' => ['ioSlicePlacement' => true]];
        $this->pmssWriteFile($directory.'/cgroup.policy.local.php', $this->pmssCgroupPolicySource($local));
        $this->assertSame($local, \pmssCgroupPolicyLoad($directory));
        $this->pmssWriteFile($directory.'/cgroup.policy.php', '<?php return false;');
        $this->assertSame($local, \pmssCgroupPolicyLoad($directory));
    }

    public function testSymlinkedLocalPolicyIsNotExecuted(): void
    {
        $policy = ['cgroup' => ['ioSlicePlacement' => false]];
        $directory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        $target = $this->pmssWritePhpArrayFixture(['cgroup' => ['ioSlicePlacement' => true]]);
        $this->pmssCreateSymlinkOrSkip($target, $directory.'/cgroup.policy.local.php');
        $this->assertSame($policy, \pmssCgroupPolicyLoad($directory));
    }

    public function testReplacingShippedPolicyKeepsPlacementUntilExplicitLocalRollback(): void
    {
        $directory = $this->pmssMakeCgroupPolicyConfigDir(['cgroup' => ['ioSlicePlacement' => false]]);
        $this->pmssWriteFile($directory.'/template.systemd.io-placement.conf', "[Manager]\nDefaultIOAccounting=no\n");
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $directory, 'PMSS_DRY_RUN' => '0'], function () use ($directory): void {
            $manager = $directory.'/manager';
            $dropin = $manager.'/90-pmss-io-placement.conf';
            $log = static function (string $message): void {};
            $localFile = $directory.'/cgroup.policy.local.php';
            $this->pmssWriteFile($localFile, $this->pmssCgroupPolicySource(['cgroup' => ['ioSlicePlacement' => true]]));
            $this->assertTrue(\pmssCgroupIoPlacementConfigure(\pmssCgroupPolicyLoad(), 'v1', $manager, $log));
            $this->assertTrue(is_file($dropin));

            // Model the updater replacing only its shipped file; no updater or systemd runs.
            $this->pmssWriteCgroupPolicyFixture($directory, ['ioWeight' => 200, 'cgroup' => ['ioSlicePlacement' => false]]);
            $this->assertTrue(\pmssCgroupIoPlacementConfigure(\pmssCgroupPolicyLoad(), 'v1', $manager, $log));
            $this->assertSame("[Manager]\nDefaultIOAccounting=no\n", $this->pmssReadFileOrEmpty($dropin));
            $this->assertSame(200, \pmssCgroupPolicyLoad()['ioWeight']);

            $this->pmssWriteFile($localFile, $this->pmssCgroupPolicySource(['cgroup' => ['ioSlicePlacement' => false]]));
            $this->assertTrue(\pmssCgroupIoPlacementConfigure(\pmssCgroupPolicyLoad(), 'v1', $manager, $log));
            $this->assertFalse(file_exists($dropin));
        });
    }
}
