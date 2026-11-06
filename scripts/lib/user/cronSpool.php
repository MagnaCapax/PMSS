<?php
/**
 * Deletion-only cleanup for cron spools whose owning UID has no account.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/pathSafety.php';

/**
 * Inspect metadata only; never read, reassign or execute a former user's jobs.
 *
 * @return array{status:string,uid:?int}
 */
function pmssUserCronSpoolRemoveOrphan(string $path, ?callable $uidLookup = null, bool $dryRun = false): array
{
    $result = array('status' => 'unsafe', 'uid' => null);
    if (!pmssPathTargetIsSafe($path, false)) {
        return $result;
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        $result['status'] = 'absent';
        return $result;
    }
    if (($stat['mode'] & 0170000) !== 0100000) {
        return $result;
    }

    $result['uid'] = (int) $stat['uid'];
    $uidLookup = $uidLookup ?? 'posix_getpwuid';
    if (!is_callable($uidLookup) || $uidLookup($result['uid']) !== false) {
        $result['status'] = 'claimed';
        return $result;
    }

    // A concurrent crontab install or ownership repair must survive this pass.
    clearstatcache(true, $path);
    $current = @lstat($path);
    if (!is_array($current) || !pmssFilesystemStatSameInode($stat, $current)
        || $current['uid'] !== $stat['uid'] || $current['mode'] !== $stat['mode']
        || !pmssPathTargetIsSafe($path, false)) {
        $result['status'] = 'changed';
        return $result;
    }
    if ($dryRun) {
        $result['status'] = 'would_remove';
        return $result;
    }
    $removed = @unlink($path);
    clearstatcache(true, $path);
    $result['status'] = $removed && @lstat($path) === false ? 'removed' : 'remove_failed';
    return $result;
}

/** Sweep direct spool entries; callers provide a metadata-only audit sink. */
function pmssUserCronSpoolSweep(string $spoolDir, callable $log, ?callable $uidLookup = null, bool $dryRun = false): bool
{
    if (!pmssPathTargetIsSafe($spoolDir, true)
        || ($entries = pmssDirectoryEntriesRead($spoolDir)) === false) {
        $log(array('status' => 'scan_failed', 'path' => $spoolDir));
        return false;
    }

    $success = true;
    foreach ($entries as $name) {
        $path = $spoolDir.'/'.$name;
        $result = pmssUserCronSpoolRemoveOrphan($path, $uidLookup, $dryRun);
        if (in_array($result['status'], array('absent', 'claimed'), true)) {
            continue;
        }
        $log(array_merge(array('user' => $name, 'path' => $path), $result));
        if (!in_array($result['status'], array('removed', 'would_remove'), true)) {
            $success = false;
        }
    }
    return $success;
}
