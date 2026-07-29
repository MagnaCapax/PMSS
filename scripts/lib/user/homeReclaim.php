<?php
/**
 * Helpers for reclaiming terminated user homes outside the foreground flow.
 *
 * The terminate script owns account cleanup; this module owns only the
 * rename-aside path contract, reclaim lock, and detached reclaim command.
 *
 * BACKWARD-COMPATIBILITY SHIM — do not delete as dead code (Refs #729, ADR 0033).
 * terminateUser.php stopped renaming homes aside on 2026-07-29; these helpers remain
 * so the sweep can still recognise and reap /home/.terminating-* directories created
 * by earlier releases. Retire on PMSS's usual multi-year compatibility horizon.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/identity.php';
require_once dirname(__DIR__).'/runtime.php';

const PMSS_USER_HOME_RECLAIM_LOG = '/var/log/pmss/user-home-reclaim.log';

/** Build the hidden /home path used while a terminated home is reclaimed. */
function pmssUserHomeReclaimPathBuild(string $username, ?int $timestamp = null, ?int $pid = null, int $counter = 0): string
{
    $normalized = pmssUsernameNormalizeIfValid($username);
    if ($normalized === null) {
        return '';
    }

    $stamp = gmdate('YmdHis', $timestamp ?? time());
    $pidPart = (string) max(1, (int) ($pid ?? (function_exists('getmypid') ? getmypid() : 1)));
    $suffix = $counter > 0 ? '-'.$counter : '';

    return '/home/.terminating-'.$normalized.'-'.$stamp.'-'.$pidPart.$suffix;
}

/** Return the PMSS username encoded in a reclaim path, or null if unsafe. */
function pmssUserHomeReclaimPathUsername(string $path): ?string
{
    $base = basename($path);
    if (preg_match('/^\.terminating-([a-z][a-z0-9]{0,7})-\d{14}-\d+(?:-\d+)?$/D', $base, $matches) !== 1) {
        return null;
    }

    return pmssUsernameIsValid($matches[1]) ? $matches[1] : null;
}

/** Validate that a reclaim target is exactly a hidden terminating directory under /home. */
function pmssUserHomeReclaimPathIsSafe(string $path): bool
{
    if (pmssUserHomeReclaimPathUsername($path) === null || strpos($path, '/home/.terminating-') !== 0) {
        return false;
    }
    if (is_link($path)) {
        return false;
    }
    if (!file_exists($path)) {
        return true;
    }

    $real = realpath($path);
    return $real !== false && $real === $path && is_dir($real);
}

/** Return the first unused reclaim path for a username. */
function pmssUserHomeReclaimPathNext(string $username): string
{
    for ($counter = 0; $counter < 100; $counter++) {
        $path = pmssUserHomeReclaimPathBuild($username, null, null, $counter);
        if ($path !== '' && !file_exists($path) && !is_link($path)) {
            return $path;
        }
    }

    return '';
}

/** Build the detached, low-priority command that reclaims a renamed home tree. */
function pmssUserHomeReclaimLaunchCommand(string $targetPath): string
{
    if (!pmssUserHomeReclaimPathIsSafe($targetPath)) {
        return '';
    }

    $launcher = 'if command -v ionice >/dev/null 2>&1; then exec ionice -c3 nice -n 19 "$@"; fi; exec nice -n 19 "$@"';
    $command = pmssBuildCommand('sh', array(
        '-c',
        $launcher,
        'pmss-home-reclaim',
        'php',
        '/scripts/util/userHomeReclaim.php',
        '--confirm',
        $targetPath,
    ));

    return 'nohup '.$command.' >> '.escapeshellarg(PMSS_USER_HOME_RECLAIM_LOG).' 2>&1 &';
}
