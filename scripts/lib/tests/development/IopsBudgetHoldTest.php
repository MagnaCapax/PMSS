<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/iopsLimit.php';
require_once dirname(__DIR__, 2).'/user/iopsLimitEnforcer.php';

/**
 * Monthly IOPS budget (#1307): judged on the last 7 days scaled to 30, an over-budget account is held at least
 * 3 days, and the held /home read cap is the budget's own rate with a floor of 25, only where the kernel can hold it.
 * Expected values come from the budget as ruled: 10.5M operations per 30 days (350K per day).
 */
class IopsBudgetHoldTest extends TestCase
{
    private const BUDGET = 10500000;

    private function stats(float $weekRead, float $weekWrite, float $month = 0.0): string
    {
        return $this->pmssWriteTempFile('iops-budget-stats-', serialize([
            'io_read_ops' => ['raw' => ['month' => $month, 'week' => $weekRead]],
            'io_write_ops' => ['raw' => ['month' => 0.0, 'week' => $weekWrite]],
        ]));
    }

    public function testBudgetUsageIsTheLastSevenDaysScaledToThirty(): void
    {
        // 400K ops/day for a week: 2.8M -> 12M per 30 days, over a 10.5M budget.
        $this->assertSame(12000000, \pmssIopsBudgetUsage($this->stats(2000000, 800000)));
        // 300K ops/day: 2.1M -> 9M, within budget, even with a heavy older month total.
        $this->assertSame(9000000, \pmssIopsBudgetUsage($this->stats(2100000, 0, 40000000)));
        // The month window stays readable for its other users.
        $this->assertSame(40000000, \pmssReadUserMonthlyIopsUsage($this->stats(2100000, 0, 40000000)));
    }

    public function testOverBudgetAccountIsHeldThreeDaysBeforeRelease(): void
    {
        $day = 86400;
        foreach ([
            [12000000, false, 0, 'enforce'],          // over budget, not yet marked
            [12000000, true, 5 * $day, 'none'],       // still over budget: stays held
            [9000000, true, 2 * $day, 'none'],        // back within budget after 2 days: still held
            [9000000, true, 3 * $day, 'restore'],     // within budget and 3 days held: released
            [9000000, false, 0, 'none'],              // within budget, never marked
        ] as $case) {
            $plan = \pmssIopsLimitEnforcementPlan(self::BUDGET, $case[0], $case[1], $case[2]);
            $this->assertSame($case[3], $plan['action'], json_encode($case));
        }
        // A budget removed (0) releases at once.
        $this->assertSame('restore', \pmssIopsLimitEnforcementPlan(0, 12000000, true, 60)['action']);
    }

    public function testHeldReadCapIsTheBudgetRateWithAFloorOf25(): void
    {
        $this->assertSame(25, \pmssIopsBudgetReadCap(self::BUDGET));      // ~4 IOPS average -> floor
        $this->assertSame(300, \pmssIopsBudgetReadCap(777000000));       // a large budget keeps its own rate
    }

    public function testHeldReadCapNeedsMarkerBudgetAndKernel(): void
    {
        $runtime = $this->pmssMakeTempDir('iops-budget-runtime-');
        mkdir($runtime.'/iopsLimits', 0700);
        mkdir($runtime.'/iopsLimitEnforced', 0700);
        file_put_contents($runtime.'/iopsLimits/acct1', (string) self::BUDGET);
        file_put_contents($runtime.'/iopsLimits/acct2', (string) self::BUDGET);

        // Budget set, no marker: no cap.
        $this->assertSame(null, \pmssIopsBudgetHeldReadCap('acct2', true, $runtime));
        file_put_contents($runtime.'/iopsLimitEnforced/acct1', (string) (time() - 3600));
        $this->assertSame(25, \pmssIopsBudgetHeldReadCap('acct1', true, $runtime));
        // A kernel that cannot hold read caps gets none.
        $this->assertSame(null, \pmssIopsBudgetHeldReadCap('acct1', false, $runtime));
        // Marked but budget removed: no cap.
        file_put_contents($runtime.'/iopsLimits/acct1', '0');
        $this->assertSame(null, \pmssIopsBudgetHeldReadCap('acct1', true, $runtime));
    }

    public function testMarkerTimeFallsBackToMtimeForAnOldMarker(): void
    {
        $runtime = $this->pmssMakeTempDir('iops-budget-marker-');
        $path = $runtime.'/m';
        $this->assertSame(null, \pmssIopsBudgetMarkerTime($path));
        file_put_contents($path, '1700000000');
        $this->assertSame(1700000000, \pmssIopsBudgetMarkerTime($path));
        file_put_contents($path, '');
        touch($path, 1600000000);
        $this->assertSame(1600000000, \pmssIopsBudgetMarkerTime($path));
    }

    public function testEnforceLogLineStatesTheCapOnlyWhereOneIsHeld(): void
    {
        $held = \pmssIopsLimitLogLine('enforce', false, self::BUDGET, 12000000, 100, 25, 1764000000);
        $this->assertTrue(strpos($held, '/home read cap 25 IOPS, held at least until 2025-11-24 16:00 UTC') !== false, $held);
        $none = \pmssIopsLimitLogLine('enforce', false, self::BUDGET, 12000000, 100);
        $this->assertTrue(strpos($none, 'no read cap on this kernel') !== false, $none);
        $this->assertFalse(strpos($none, 'cap 25') !== false);
    }

    public function testCapWriterAppliesTheBudgetCapBeforeTheClearBranch(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/cron/cgroupIopsLimitApply.php');
        $cap = strpos($source, 'pmssIopsBudgetHeldReadCap(');
        $clear = strpos($source, 'Off leaves no dynamic read cap behind');
        $this->assertTrue($cap !== false && $clear !== false && $cap < $clear, 'budget cap must be set before the clear branch');
        $this->assertTrue(strpos($source, "'monthly-iops-budget'") !== false, 'budget cap writes carry their own audit reason');
    }
}
