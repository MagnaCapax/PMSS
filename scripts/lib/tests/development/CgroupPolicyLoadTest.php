<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/policy.php';

/** Hermetic coverage: the cgroup policy comes from the shipped file only (ADR 0233). */
final class CgroupPolicyLoadTest extends TestCase
{
    public function testShippedPolicyLoadsFromTheConfiguredDirectory(): void
    {
        $policy = ['ioWeight' => 100, 'cgroup' => ['ioSlicePlacement' => false]];
        $directory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        $this->assertSame($policy, \pmssCgroupPolicyLoad($directory));
        $this->assertSame($policy, \pmssCgroupPolicyLoad($directory.'/'));
        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $directory], function () use ($policy): void {
            $this->assertSame($policy, \pmssCgroupPolicyLoad());
        });
        $this->assertSame([], \pmssCgroupPolicyLoad($directory.'/missing'));
    }

    public function testLeftoverLocalPolicyIsIgnored(): void
    {
        $policy = ['cgroup' => ['ioSlicePlacement' => true, 'dynamicIops' => ['mode' => 'enforce']]];
        $directory = $this->pmssMakeCgroupPolicyConfigDir($policy);
        $local = ['cgroup' => ['ioSlicePlacement' => false, 'dynamicIops' => ['mode' => 'off']]];
        $this->pmssWriteFile($directory.'/cgroup.policy.local.php', $this->pmssCgroupPolicySource($local));
        $this->assertSame($policy, \pmssCgroupPolicyLoad($directory));
        // Without a valid shipped policy a local file still supplies nothing.
        $this->pmssWriteFile($directory.'/cgroup.policy.php', '<?php return false;');
        $this->assertSame([], \pmssCgroupPolicyLoad($directory));
    }
}
