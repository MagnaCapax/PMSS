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

    public function testDropinContentRepairAppendsUnitToBareSubMibMemoryMax(): void
    {
        $plan = \pmssUserCgroupSliceDropinContentRepairBareMemoryMax(
            "[Slice]\nMemoryMax=750\nMemoryLimit=750M\nMemoryHigh=600M\n"
        );

        $this->assertTrue($plan['changed']);
        $this->assertSame("[Slice]\nMemoryMax=750M\nMemoryLimit=750M\nMemoryHigh=600M\n", $plan['content']);
        $this->assertSame(['750'], $plan['values']);
    }

    public function testDropinContentRepairLeavesSuffixedAndByteSizedValuesAlone(): void
    {
        foreach ([
            "[Slice]\nMemoryMax=750M\n",
            "[Slice]\nMemoryMax=1048576\n",
            "[Slice]\nMemoryMax=infinity\n",
            "[Slice]\nMemoryMax=0\n",
            "[Slice]\nMemoryMax=750\nMemoryHigh=600M\n",
        ] as $content) {
            $plan = \pmssUserCgroupSliceDropinContentRepairBareMemoryMax($content);
            $this->assertFalse($plan['changed']);
            $this->assertSame($content, $plan['content']);
        }
    }

    public function testDropinFileRepairWritesBackupAndSkipsSymlink(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-user-slice-dropin-');
        $file = $this->pmssWriteFile($dir.'/90-pmss-user.conf', "[Slice]\nMemoryMax=512\nMemoryLimit=512M\n");
        $link = $dir.'/91-link.conf';
        symlink($file, $link);
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $this->assertTrue(\pmssUserCgroupSliceDropinFileRepairBareMemoryMax($file, $logger));
        $this->assertSame("[Slice]\nMemoryMax=512M\nMemoryLimit=512M\n", (string) file_get_contents($file));
        $this->assertTrue(count(glob($file.'.pmss-backup-*') ?: []) > 0);
        $this->assertFalse(\pmssUserCgroupSliceDropinFileRepairBareMemoryMax($link, $logger));
        $this->assertStringContainsString('Skipping unsafe user slice drop-in target', implode("\n", $messages));
    }
}
