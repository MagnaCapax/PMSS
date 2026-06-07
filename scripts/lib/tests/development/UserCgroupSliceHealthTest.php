<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userCgroupSliceHealth.php';

class UserCgroupSliceHealthTest extends TestCase
{
    public function testExpectedMemoryMaxUsesCanonicalCgroupClamp(): void
    {
        $this->assertSame(1280 * 1048576, \pmssUserCgroupSliceExpectedMemoryMaxBytes(['ramMiB' => 1024], 16384));
        $this->assertSame(972 * 1048576, \pmssUserCgroupSliceExpectedMemoryMaxBytes(['ramMiB' => 900], 1024));
    }

    public function testRefreshPlanDetectsTemplateDefaultBelowStoredPlan(): void
    {
        $plan = \pmssUserCgroupSliceMemoryRefreshPlan(
            'alice',
            ['ramMiB' => 8192],
            ['MemoryMax' => (string) (750 * 1048576)],
            32768
        );

        $this->assertTrue($plan['needed']);
        $this->assertSame('memory_max_below_plan', $plan['reason']);
        $this->assertSame(750 * 1048576, $plan['currentMemoryMaxBytes']);
        $this->assertSame(10240 * 1048576, $plan['expectedMemoryMaxBytes']);
    }

    public function testRefreshPlanSkipsHealthyOrUnreadableSlice(): void
    {
        foreach ([
            [(string) (12000 * 1048576), 'memory_max_at_or_above_plan'],
            ['infinity', 'memory_max_unset_or_unreadable'],
        ] as $case) {
            $plan = \pmssUserCgroupSliceMemoryRefreshPlan('alice', ['ramMiB' => 8192], ['MemoryMax' => $case[0]], 32768);

            $this->assertFalse($plan['needed']);
            $this->assertSame($case[1], $plan['reason']);
        }
    }

    public function testApplyCommandUsesStoredCgroupArgs(): void
    {
        $command = \pmssUserCgroupSliceApplyCommand('alice', [
            'ramMiB' => 8192,
            'IOWeight' => 300,
            'cpuQuotaPercent' => 'infinity',
        ]);

        $this->assertTrue(is_string($command));
        $this->assertStringContainsAllStrings([
            "'/scripts/util/userConfigCgroup.php' 'alice' '--apply' '--memory-high=8192'",
            "'--io-weight=300'",
            "'--cpu-quota-percent=infinity'",
        ], $command);
    }
}
