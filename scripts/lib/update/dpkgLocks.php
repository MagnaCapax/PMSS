<?php
/**
 * Shared bounded lock waiting for package recovery and distribution upgrades.
 *
 * Observe lock users only: never delete lock files or signal their owners.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Wait for package-manager file users to leave within the caller's budget. */
function pmssWaitForDpkgLocks(int $timeoutSeconds = 1800, int $sleepSeconds = 5, string $context = 'dist-upgrade'): bool
{
    if (pmssCommandPath('fuser') === '') {
        logMessage('[WARN] '.$context.': fuser not available; skipping dpkg lock checks');
        return true;
    }

    $paths = ['/var/lib/dpkg/lock-frontend', '/var/lib/dpkg/lock', '/var/lib/apt/lists/lock', '/var/cache/apt/archives/lock'];
    $start = time();
    $announced = false;
    while (pmssDpkgLockActive($paths)) {
        if (!$announced) {
            logMessage($context.': dpkg lock detected; waiting for release');
            $announced = true;
        }
        $remaining = $timeoutSeconds - (time() - $start);
        if ($remaining <= 0) {
            logMessage('[ERROR] '.$context.': dpkg lock did not clear within '.$timeoutSeconds.' seconds');
            return false;
        }
        // Clamp the last pause so a slow exit cannot extend the wait budget.
        sleep(min(max(1, $sleepSeconds), $remaining));
    }

    if ($announced) {
        logMessage($context.': dpkg lock cleared; continuing');
    }
    return true;
}

/** Check open package lock files using the existing fuser observation method. */
function pmssDpkgLockActive(array $paths): bool
{
    foreach ($paths as $path) {
        $rc = 1;
        @exec('fuser '.escapeshellarg($path).' 2>/dev/null', $output, $rc);
        if ($rc === 0) {
            return true;
        }
    }
    return false;
}
