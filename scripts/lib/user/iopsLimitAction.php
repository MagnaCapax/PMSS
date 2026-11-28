<?php
/**
 * One account's monthly IOPS budget action: hold (enforce) or release (restore).
 *
 * The caller passes the side effects in $io so the order of marker, restore step and log line can be tested:
 *   run(string $label, string $command): int   runs a step, returns its exit code
 *   markerWrite(): bool / markerRemove(): bool  write or remove the hold marker
 *   log(string $line): void                    one user-log line
 *
 * On cgroup v1 ($clamp false) the held read cap belongs to cgroupIopsLimitApply.php, which reads the marker, so a
 * release removes the marker FIRST and the systemd restore after it is best-effort cleanup of old drop-ins: a missing
 * RAM baseline or a failed step logs one warning and never keeps the cap in place (#1307). On v2 the marker stays
 * until the restore succeeds, as before.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * @param array{action:string,limit:int,usage:int} $plan
 * @param array{run:callable,markerWrite:callable,markerRemove:callable,log:callable} $io
 */
function pmssIopsLimitApplyPlan(string $user, array $plan, bool $clamp, ?int $budgetCap, int $now, ?string $restoreCommand, array $io): void
{
    $limit = $plan['limit'];
    $usage = $plan['usage'];

    if ($plan['action'] === 'enforce') {
        $throttleIops = 100;
        if ($clamp && $io['run']('Applying monthly IOPS throttle for '.$user, pmssIopsLimitBuildThrottleCommand($user, $throttleIops)) !== 0) {
            return;
        }
        // The "held until" line is true only once the marker exists; without it nothing holds the cap.
        if (!$io['markerWrite']()) {
            $io['log'](sprintf('[WARN] monthly IOPS budget exceeded (limit=%d usage=%d) but the hold marker could not be written', $limit, $usage));
            return;
        }
        $io['log'](pmssIopsLimitLogLine('enforce', $clamp, $limit, $usage, $throttleIops, $budgetCap, $now + PMSS_IOPS_BUDGET_HOLD_SECONDS));
        return;
    }
    if ($plan['action'] !== 'restore') {
        return;
    }

    if ($clamp) {
        // v2: the systemd restore is what lifts the clamp, so the marker goes only after it succeeds.
        if ($restoreCommand === null) {
            $io['log']('[WARN] IOPS throttle restore skipped: missing RAM baseline');
            return;
        }
        if ($io['run']('Restoring baseline cgroup IOPS for '.$user, $restoreCommand) === 0) {
            $io['markerRemove']();
            $io['log'](pmssIopsLimitLogLine('restore', $clamp, $limit, $usage, 0));
        }
        return;
    }

    // v1: removing the marker is the release; the restore below only reverts leftover slice properties.
    if ($io['markerRemove']()) {
        $io['log'](pmssIopsLimitLogLine('restore', $clamp, $limit, $usage, 0));
    }
    if ($restoreCommand === null) {
        $io['log']('[WARN] monthly IOPS budget released; leftover cgroup slice properties were not reverted: missing RAM baseline');
        return;
    }
    if ($io['run']('Restoring baseline cgroup IOPS for '.$user, $restoreCommand) !== 0) {
        $io['log']('[WARN] monthly IOPS budget released; reverting leftover cgroup slice properties failed');
    }
}
