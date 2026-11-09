<?php
/** Guarded integer writes and account slice privacy for direct cgroup-v1 enforcement. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/filesystem.php';

/** Refuse links, directories, and missing targets before direct cgroup writes. */
function pmssCgroupDirectWritableFileTarget(string $path): bool
{
    return $path !== ''
        && strpos($path, "\0") === false
        && is_file($path)
        && !is_link($path)
        && is_writable($path);
}

/** Write one bounded integer target and reject failed or short sysfs writes. */
function pmssCgroupDirectIntegerFileWrite(string $path, int $value, int $minimum, int $maximum): bool
{
    return $minimum <= $maximum && $value >= $minimum && $value <= $maximum
        && pmssCgroupDirectWritableFileTarget($path)
        && pmssFileWriteComplete($path, (string) $value);
}

/**
 * Keep an account's cgroup counters and limits readable only by that account and root (ADR 0232):
 * its slice directory in every mounted hierarchy becomes root:<account group> 0750 and user.slice
 * 0711, so other accounts can neither list slices nor read them. Root readers are unaffected.
 * Systemd creates slices 0755, so each IOPS cycle re-applies the modes.
 */
function pmssCgroupDirectSlicePrivacyApply(int $uid, int $gid, bool $dryRun, string $cgroupRoot = '/sys/fs/cgroup'): bool
{
    // A shared or system group would hand the slice to other accounts.
    if ($uid < 1000 || $gid < 1000) return false;
    $ok = true;
    foreach (array_merge(glob($cgroupRoot.'/*/user.slice', GLOB_ONLYDIR) ?: [], glob($cgroupRoot.'/user.slice', GLOB_ONLYDIR) ?: []) as $parent) {
        $slice = $parent.'/user-'.$uid.'.slice';
        foreach ([[$parent, 0, 0711], [$slice, $gid, 0750]] as [$dir, $group, $mode]) {
            $stat = @lstat($dir);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== 0) continue;
            if ($stat['gid'] === $group && ($stat['mode'] & 07777) === $mode) continue;
            if ($dryRun) continue;
            if (!@chgrp($dir, $group) || !@chmod($dir, $mode)) $ok = false;
        }
    }
    return $ok;
}
