<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Guards the #850 double-lock class: a cron script must NOT be BOTH wrapped in an
 * outer `flock` in root.cron AND self-lock internally on the SAME lock path. When it
 * is, the outer flock holds the lock for the whole child run, the script's own
 * pmssLockFileAcquire() fails every invocation, and it skips 100% of runs
 * (commit cff1c67a on trafficLog, 2026-09-01, silently disabled egress accounting +
 * traffic-limit enforcement fleet-wide; reverted in 261bd3e9, 2026-09-02).
 *
 * Invariant enforced: for every `flock ... pmss-<name>.lock /scripts/cron/<script>.php`
 * line in root.cron, the target script does NOT self-lock on that same `pmss-<name>.lock`.
 * Pick ONE mechanism per script — the in-script pmssLockFileAcquire (canonical, protects
 * every invocation path incl. manual/test) OR the outer cron flock — never both on one path.
 */
class CronDoubleLockGuardTest extends TestCase
{
    public function testNoCronScriptIsBothOuterFlockedAndSelfLockedOnTheSamePath(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');

        // Match: flock ... pmss-<NAME>.lock ... /scripts/cron/<SCRIPT>.php  (single line)
        $matched = preg_match_all(
            '#\bflock\b[^\r\n]*?pmss-([A-Za-z0-9]+)\.lock[^\r\n]*?/scripts/cron/([A-Za-z0-9]+)\.php#',
            $cron,
            $rows,
            PREG_SET_ORDER
        );
        // Zero flock-wrapped cron entries is a VALID state (e.g. all migrated to in-script
        // locks) — the invariant is only violated when a match ALSO self-locks. No lower bound.
        if ($matched === 0) {
            $this->assertTrue(true, 'root.cron has no outer-flock cron entries (all in-script or none) — invariant holds vacuously');
            return;
        }

        foreach ($rows as $row) {
            $lockName = $row[1];   // e.g. trafficLog  (from pmss-trafficLog.lock)
            $script   = $row[2];   // e.g. trafficLog  (from /scripts/cron/trafficLog.php)
            $src = $this->pmssReadRepoFile('scripts/cron/'.$script.'.php');

            $referencesSameLock = strpos($src, 'pmss-'.$lockName.'.lock') !== false;
            $selfLocks = strpos($src, 'pmssLockFileAcquire') !== false
                || strpos($src, 'pmssRuntimeLockPath') !== false
                || strpos($src, 'flock(') !== false;

            $this->assertFalse(
                $referencesSameLock && $selfLocks,
                'DOUBLE-LOCK (#850): scripts/cron/'.$script.'.php self-locks on pmss-'.$lockName.'.lock '
                .'AND root.cron also outer-flock-wraps it on the same path — the inner acquire fails every '
                .'run and the script skips 100%%. Use ONE lock: drop the outer flock from root.cron OR remove '
                .'the in-script lock; never both on the same lock path.'
            );
        }
    }
}
