<?php
/**
 * Re-check account-isolation host state between PMSS updates (ADR 0294).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../fstab.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../sessionHistoryPermissions.php';

/** Return /proc options from the mount table, or null when unavailable. */
function pmssIsolationProcOptionsRead(string $mountsPath): ?array
{
    $lines = @file($mountsPath, FILE_IGNORE_NEW_LINES);
    $entry = $lines === false ? null : pmssFstabMountEntryRead($lines, '/proc', 'proc');
    return $entry === null ? null : explode(',', $entry['columns'][3]);
}

/** Accept both kernel spellings of hidepid=2, with no group exemption. */
function pmssIsolationProcOptionsCorrect(array $options): bool
{
    $hidden = false;
    foreach ($options as $option) {
        if (strpos($option, 'gid=') === 0) return false;
        if (strpos($option, 'hidepid=') === 0) {
            if (!in_array($option, ['hidepid=2', 'hidepid=invisible'], true)) return false;
            $hidden = true;
        }
    }
    return $hidden;
}

/**
 * Restore the host account-isolation baseline while holding the update lock.
 *
 * Paths and the command runner are injectable for hermetic tests. The runner
 * receives one fixed command string and returns its exit code. Account home
 * directories remain the responsibility of the per-account permission sweep.
 */
function pmssIsolationStateRecheck(
    string $lockFile = '/var/lib/pmss/update.lock',
    string $mountsPath = '/proc/mounts',
    string $root = '',
    ?callable $runner = null
): void {
    $lock = pmssLockFileAcquire($lockFile, true, 'c', true, true, $busy);
    if ($lock === false) {
        logMessage($busy ? '[SKIP] Account isolation re-check: update lock busy'
            : '[WARN] Account isolation re-check: update lock unavailable');
        return;
    }

    try {
        // Match session-record dry runs: no commands, chmods or repair claims.
        if (getenv('PMSS_DRY_RUN') === '1') return;
        $runner = $runner ?? static function (string $command): int {
            return runStep('Restoring /proc account isolation', $command);
        };
        $options = pmssIsolationProcOptionsRead($mountsPath);
        if ($options === null) {
            logMessage('[WARN] Account isolation re-check: /proc mount options unavailable');
        } elseif (!pmssIsolationProcOptionsCorrect($options)) {
            $hasGid = false;
            foreach ($options as $option) {
                if (strpos($option, 'gid=') === 0) $hasGid = true;
            }
            // Omitting a stale group option alone does not clear it live.
            $commands = $hasGid ? ['mount -o remount,hidepid=2,gid=0 /proc'] : [];
            $commands[] = 'mount -o remount,hidepid=2 /proc';
            foreach ($commands as $command) {
                $rc = $runner($command);
                if ($rc !== 0) logMessage('[WARN] /proc account isolation remount failed (rc='.$rc.')');
            }
            $after = pmssIsolationProcOptionsRead($mountsPath);
            if ($after === null || !pmssIsolationProcOptionsCorrect($after)) {
                logMessage('[WARN] /proc still needs hidepid=2 without a group exemption after remount');
            } else {
                logMessage('[REPAIR] /proc needed hidepid=2 without a group exemption; remounted with hidepid=2');
            }
        }

        $home = rtrim($root, '/').'/home';
        clearstatcache(true, $home);
        $stats = @lstat($home);
        // Only the real /home directory: never follow a link or sweep accounts.
        if ($stats !== false && ($stats['mode'] & 0170000) === 0040000 && ($stats['mode'] & 0006) !== 0) {
            $mode = ($stats['mode'] & 07777) & ~0006;
            if (@chmod($home, $mode)) {
                logMessage(sprintf('[REPAIR] /home granted other-read/write; mode %04o -> %04o', $stats['mode'] & 07777, $mode));
            } else {
                logMessage('[WARN] Cannot remove other-read/write from /home');
            }
        }
        try {
            pmssSessionHistoryPermissionsHarden($root, static function (string $message): void { logMessage($message); });
        } catch (\RuntimeException $exception) {
            logMessage('[WARN] '.$exception->getMessage());
        }
    } finally {
        pmssLockHandleRelease($lock);
    }
}
