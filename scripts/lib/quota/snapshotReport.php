<?php
/** Bounded quota report acquisition and stop-on-timeout sweep. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/**
 * Deadline for one `quota` report. The refresh cron runs every minute, so a call that
 * outlives this, plus the runner's kill grace, is hung, not slow (GH #1183).
 */
const PMSS_QUOTA_REPORT_TIMEOUT_SECONDS = 20;

/**
 * Run one `quota` report under a deadline through the process-group runner (ADR 0035).
 *
 * The runner kills the whole group on expiry and stops waiting, so a call stuck in the
 * kernel leaves at most one stuck `quota` behind instead of blocking the caller. The
 * lock-fd close prefix keeps that stuck child from holding the caller's cron lock.
 * Output of an expired call is dropped: partial quota output is not a snapshot.
 *
 * @return array{rc:int,lines:array<int,string>,timed_out:bool} lines are shaped like exec() output
 */
function pmssQuotaReportCapture(string $command, int $timeoutSec = PMSS_QUOTA_REPORT_TIMEOUT_SECONDS): array
{
    $startedAt = microtime(true);
    $result = pmssCommandCapture(pmssLockChildClosePrefix().$command, $timeoutSec);
    $rc = (int) $result['rc'];
    // An expired deadline returns 124 (SIGTERM) or 137 (SIGKILL); the elapsed check keeps an
    // unrelated early 137, such as an OOM kill, from reading as a hang.
    $timedOut = ($rc === 124 || $rc === 137) && microtime(true) - $startedAt >= $timeoutSec;
    $stdout = $timedOut ? '' : rtrim($result['stdout'], "\n");
    $lines = $stdout === '' ? [] : array_map('rtrim', explode("\n", $stdout));
    return ['rc' => $rc, 'lines' => $lines, 'timed_out' => $timedOut];
}

/**
 * Refresh accounts in order and stop at the first one whose quota call timed out.
 *
 * A hung `quota` usually means the quota state of /home is wedged, not that one account
 * is broken, so the next call would hang the same way and leave one stuck child per
 * account per run. Stopping ends the run, which releases the cron lock, and the next
 * run starts again from the first account.
 *
 * @param callable $refreshUser callable(string $user): bool, false only when that account's quota call timed out
 * @param callable $log         callable(string $message): void
 * @return int accounts left unrefreshed by a stop, 0 when the sweep completed
 */
function pmssQuotaSweepRun(array $users, callable $refreshUser, callable $log): int
{
    $remaining = count($users);
    foreach ($users as $user) {
        $remaining--;
        if ($refreshUser($user) === false) {
            $log(sprintf('Quota sweep stopped after a hung quota call for %s; %d remaining account(s) keep their previous snapshot until the next run', $user, $remaining));
            return $remaining;
        }
    }
    return 0;
}
