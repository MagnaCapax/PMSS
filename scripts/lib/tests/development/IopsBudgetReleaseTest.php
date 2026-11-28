<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/iopsLimit.php';
require_once dirname(__DIR__, 2).'/user/iopsLimitEnforcer.php';

/**
 * Monthly IOPS budget release and hold order (#1307). On cgroup v1 the hold marker is what keeps the /home read cap,
 * so a release must remove it even when the leftover systemd restore cannot run; a hold is logged only once its
 * marker exists. On v2 the marker stays until the restore that lifts the clamp succeeds.
 */
class IopsBudgetReleaseTest extends TestCase
{
    private const BUDGET = 10000000;

    /** @var array<int,array> */
    private $events = [];

    private function io(int $runRc = 0, bool $markerWrite = true, bool $markerRemove = true): array
    {
        return [
            'run' => function ($label, $command) use ($runRc) { $this->events[] = ['run', $command]; return $runRc; },
            'markerWrite' => function () use ($markerWrite) { $this->events[] = ['markerWrite']; return $markerWrite; },
            'markerRemove' => function () use ($markerRemove) { $this->events[] = ['markerRemove']; return $markerRemove; },
            'log' => function ($line) { $this->events[] = ['log', $line]; },
        ];
    }

    private function kinds(): array
    {
        return array_map(function ($e) { return $e[0]; }, $this->events);
    }

    private function logs(): array
    {
        $out = [];
        foreach ($this->events as $e) {
            if ($e[0] === 'log') {
                $out[] = $e[1];
            }
        }
        return $out;
    }

    private function restorePlan(): array
    {
        return ['action' => 'restore', 'limit' => self::BUDGET, 'usage' => 9000000];
    }

    public function testV1ReleaseWithNoRamBaselineRemovesTheMarker(): void
    {
        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $this->restorePlan(), false, 30, 1764000000, null, $this->io());
        $this->assertSame(['markerRemove', 'log', 'log'], $this->kinds());
        $logs = $this->logs();
        $this->assertTrue(strpos($logs[0], 'usage back within budget') !== false, $logs[0]);
        $this->assertTrue(strpos($logs[1], '[WARN]') === 0 && strpos($logs[1], 'not reverted') !== false, $logs[1]);
    }

    public function testV1ReleaseWithAFailingRestoreStepStillRemovesTheMarker(): void
    {
        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $this->restorePlan(), false, 30, 1764000000, 'restore-cmd', $this->io(1));
        $this->assertSame(['markerRemove', 'log', 'run', 'log'], $this->kinds());
        $this->assertSame('restore-cmd', $this->events[2][1]);
        $this->assertTrue(strpos($this->logs()[1], '[WARN]') === 0, $this->logs()[1]);
    }

    public function testV1ReleaseLogsBackWithinBudgetOnlyWhenTheMarkerWasRemoved(): void
    {
        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $this->restorePlan(), false, 30, 1764000000, 'restore-cmd', $this->io(0, true, false));
        $this->assertSame(['markerRemove', 'run'], $this->kinds());
    }

    public function testFailedMarkerWriteProducesNoHeldLine(): void
    {
        $this->events = [];
        $plan = ['action' => 'enforce', 'limit' => self::BUDGET, 'usage' => 12000000];
        \pmssIopsLimitApplyPlan('acct1', $plan, false, 30, 1764000000, null, $this->io(0, false));
        $logs = $this->logs();
        $this->assertSame(1, count($logs));
        $this->assertTrue(strpos($logs[0], '[WARN]') === 0, $logs[0]);
        $this->assertFalse(strpos($logs[0], 'held at least until') !== false, $logs[0]);

        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $plan, false, 30, 1764000000, null, $this->io());
        $this->assertSame(['markerWrite', 'log'], $this->kinds());
        $this->assertTrue(strpos($this->logs()[0], 'held at least until') !== false, $this->logs()[0]);
    }

    public function testV2ReleaseBuildsTheRestoreAndAFailedStepKeepsTheMarker(): void
    {
        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $this->restorePlan(), true, null, 1764000000, 'restore-cmd', $this->io(1));
        $this->assertSame(['run'], $this->kinds());

        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $this->restorePlan(), true, null, 1764000000, 'restore-cmd', $this->io(0));
        $this->assertSame(['run', 'markerRemove', 'log'], $this->kinds());
        $this->assertTrue(strpos($this->logs()[0], 'throttle removed') !== false, $this->logs()[0]);

        $this->events = [];
        \pmssIopsLimitApplyPlan('acct1', $this->restorePlan(), true, null, 1764000000, null, $this->io());
        $this->assertSame(['log'], $this->kinds());
        $this->assertTrue(strpos($this->logs()[0], 'missing RAM baseline') !== false);
    }

    public function testV2EnforceDoesNotMarkWhenTheThrottleStepFails(): void
    {
        $this->events = [];
        $plan = ['action' => 'enforce', 'limit' => self::BUDGET, 'usage' => 12000000];
        \pmssIopsLimitApplyPlan('acct1', $plan, true, null, 1764000000, null, $this->io(1));
        $this->assertSame(['run'], $this->kinds());
    }

    public function testHoldBoundariesAtThreeDaysAndBudgetZero(): void
    {
        $hold = PMSS_IOPS_BUDGET_HOLD_SECONDS;
        $this->assertSame(3 * 86400, $hold);
        $this->assertSame('restore', \pmssIopsLimitEnforcementPlan(self::BUDGET, 9000000, true, $hold)['action']);
        $this->assertSame('none', \pmssIopsLimitEnforcementPlan(self::BUDGET, 9000000, true, $hold - 1)['action']);
        $this->assertSame('restore', \pmssIopsLimitEnforcementPlan(0, 12000000, true, 0)['action']);
        $this->assertSame('none', \pmssIopsLimitEnforcementPlan(0, 12000000, false, 0)['action']);
        $this->assertSame('none', \pmssIopsLimitEnforcementPlan(self::BUDGET, self::BUDGET, false, 0)['action']);
    }

    public function testRunLoopHandsEachAccountToTheActionFunction(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/user/iopsLimitEnforcer.php');
        $this->assertTrue(strpos($source, 'pmssIopsLimitApplyPlan($user, $plan, $clamp,') !== false);
        $this->assertTrue(strpos($source, 'pmssIntegerSettingFileRemove($markerPath)') !== false);
    }
}
