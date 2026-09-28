<?php
/**
 * Runtime/log directory convergence helpers for root cron entrypoints.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logger.php';

/**
 * Return PMSS runtime/log directories that root cron keeps present.
 *
 * NOTE: this list is the belt to pmssEnsureSafeDir's suspenders — every
 * runtime/log dir that a PMSS cron script creates on-the-fly via
 * pmssEnsureSafeDir() should ALSO be listed here. pmssEnsureSafeDir uses
 * path-safety walks that have historically blocked /var/run/* creation due
 * to the Debian /var/run -> /run symlink; this hourly cron uses plain
 * mkdir() which bypasses that check and guarantees the directories exist
 * even when the safety-helper logic regresses. See 2026-05-23 heshtok
 * pathSafety.php regression for the precedent.
 *
 * @return array<int, string>
 */
function pmssCheckDirectoriesRequiredDirectories(): array
{
    return array(
        '/var/log/pmss',
        '/var/log/pmss/traffic',
        '/var/log/pmss/traffic-ingress',
        '/var/log/pmss/cgroup',
        '/var/log/pmss/trafficStats',
        '/var/log/pmss/resources',
        '/var/run/pmss',
        '/var/run/pmss/api',
        '/var/run/pmss/trafficLimits',
        '/var/run/pmss/trafficStats',
        '/var/run/pmss/trafficIngress',
        '/var/run/pmss/resources',
        '/var/run/pmss/resourceStats',
        '/var/run/pmss/process-watchdog',
    );
}

/** Return the one directory intended for customer-readable projections. */
function pmssCheckDirectoriesPublicDirectories(): array
{
    return ['/var/lib/pmss/public'];
}

/** Ensure a public projection directory without following or taking over an unsafe path. */
function pmssCheckDirectoriesEnsurePublicDirectory(string $path, callable $log, int $ownerUid = 0, int $ownerGid = 0): bool
{
    if ($path === '') {
        $log("WARN: invalid public directory or owner: $path");
        return false;
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat !== false && (($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $ownerUid || $stat['gid'] !== $ownerGid)) {
        $log("WARN: public directory is not a root-owned real directory: $path");
        return false;
    }
    if ($stat === false && !@mkdir($path, 0755)) {
        $log("WARN: failed to create public directory: $path");
        return false;
    }
    // Recheck after creation, including a concurrent replacement of the path.
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $ownerUid || $stat['gid'] !== $ownerGid) {
        $log("WARN: public directory is not a root-owned real directory: $path");
        return false;
    }
    if (!@chmod($path, 0755)) {
        $log("WARN: failed to set mode 0755 on $path");
        return false;
    }
    return true;
}

/** Ensure one runtime/log directory exists with root-only traversal permissions. */
function pmssCheckDirectoriesEnsureDirectory(string $thisDir, callable $log, string $owner = 'root'): bool
{
    if ($thisDir === '') {
        $log('WARN: empty required directory path; skipping');
        return false;
    }

    // Inspect the path itself: is_dir() follows symlinks into unrelated trees.
    clearstatcache(true, $thisDir);
    $stat = @lstat($thisDir);
    if ($stat === false) {
        if (!@mkdir($thisDir)) {
            $log("WARN: failed to create $thisDir");
            return false;
        }
        $log("Created $thisDir");
    } elseif (($stat['mode'] & 0170000) !== 0040000) {
        // A stale plain file squatting the path would otherwise keep blocking
        // the runtime directory forever.
        $log("WARN: $thisDir exists but is not a directory; skipping");
        return false;
    }

    // Check again before chown/chmod in case creation or another process changed it.
    clearstatcache(true, $thisDir);
    $stat = @lstat($thisDir);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
        $log("WARN: $thisDir exists but is not a directory; skipping");
        return false;
    }

    $ok = true;
    if (!@chown($thisDir, $owner)) {
        $log("WARN: failed to set owner $owner on $thisDir");
        $ok = false;
    }
    if (!@chmod($thisDir, 0700)) {
        $log("WARN: failed to set mode 0700 on $thisDir");
        $ok = false;
    }

    return $ok;
}

/**
 * Keep runtime/log directories converged while continuing past individual failures.
 *
 * @param array<int, string>|null $requiredDirectories
 */
function pmssCheckDirectoriesMain(?Logger $logger = null, ?array $requiredDirectories = null): int
{
    $logger = $logger ?? new Logger('checkDirectories.php');
    $logger->msg('Verifying required directories');

    $log = static function (string $message) use ($logger): void {
        $logger->msg($message);
    };

    foreach (($requiredDirectories ?? pmssCheckDirectoriesRequiredDirectories()) as $thisDir) {
        pmssCheckDirectoriesEnsureDirectory((string) $thisDir, $log);
    }

    if ($requiredDirectories === null) {
        foreach (pmssCheckDirectoriesPublicDirectories() as $publicDir) {
            pmssCheckDirectoriesEnsurePublicDirectory($publicDir, $log);
        }
    }

    return 0;
}
