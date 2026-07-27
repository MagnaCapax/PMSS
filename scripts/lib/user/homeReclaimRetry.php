<?php
/**
 * Retry-age and locking helpers for terminated-home reclaim.
 *
 * The safety contract remains in homeReclaim.php; this module owns only
 * retry eligibility and coordination between workers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/homeReclaim.php';

const PMSS_USER_HOME_RECLAIM_RETRY_AGE = 3600;

/** Return the creation timestamp encoded in a validated reclaim path. */
function pmssUserHomeReclaimPathTimestamp(string $path): ?int
{
    $base = basename($path);
    if (preg_match('/^\.terminating-[a-z][a-z0-9]{0,7}-(\d{14})-\d+(?:-\d+)?$/D', $base, $matches) !== 1) {
        return null;
    }

    $date = \DateTimeImmutable::createFromFormat(
        '!YmdHis',
        $matches[1],
        new \DateTimeZone('UTC')
    );
    if ($date === false || $date->format('YmdHis') !== $matches[1]) {
        return null;
    }

    return $date->getTimestamp();
}

/** Return whether a validated reclaim path is old enough for a retry. */
function pmssUserHomeReclaimPathIsDue(string $path, int $now, int $minimumAge = PMSS_USER_HOME_RECLAIM_RETRY_AGE): bool
{
    if ($minimumAge < 0 || !pmssUserHomeReclaimPathIsSafe($path)) {
        return false;
    }

    $createdAt = pmssUserHomeReclaimPathTimestamp($path);
    return $createdAt !== null && $createdAt <= $now - $minimumAge;
}

/** Return the per-target lock path used by both workers and the reaper. */
function pmssUserHomeReclaimLockPath(string $targetPath): string
{
    if (!pmssUserHomeReclaimPathIsSafe($targetPath)) {
        return '';
    }

    return pmssRuntimeDir().'/user-home-reclaim-'.sha1($targetPath).'.lock';
}

/** Acquire a non-blocking per-target reclaim lock, or return null on setup failure. */
function pmssUserHomeReclaimAcquireLock(string $targetPath)
{
    $lockPath = pmssUserHomeReclaimLockPath($targetPath);
    if ($lockPath === '') {
        return null;
    }

    $runtimeDir = dirname($lockPath);
    if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0750, true) && !is_dir($runtimeDir)) {
        return null;
    }

    $handle = @fopen($lockPath, 'c');
    if (!is_resource($handle)) {
        return null;
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return false;
    }

    return $handle;
}

/** Release a reclaim lock held by the current process. */
function pmssUserHomeReclaimReleaseLock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}
