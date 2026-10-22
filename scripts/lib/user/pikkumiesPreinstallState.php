<?php
/** Root-owned Pikkumies preinstall markers: the path a marker is written to, and its cleanup for account lifecycle flows. */

require_once __DIR__.'/identity.php';
require_once dirname(__DIR__).'/pathSafety.php';

/** Clear a validated account's marker without traversing a link or directory. */
function pmssPikkumiesPreinstallStateClear(
    string $username,
    string $stateDir = '/var/lib/pmss/pikkumies-preinstall'
): bool {
    // Check the whole directory chain before constructing a destructive leaf path.
    if (!pmssValidateUsername($username) || is_link($stateDir) || !pmssPathTargetIsSafe($stateDir, true)) {
        return false;
    }

    $path = $stateDir.'/'.$username;
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        return !file_exists($path) && !is_link($path);
    }

    // lstat inspects the marker itself; unlink removes a symlink, never its target.
    $type = $stat['mode'] & 0170000;
    return ($type === 0100000 || $type === 0120000) && @unlink($path);
}

/**
 * Clear a validated account's detached Pikkumies job files (root-only output, staged input, lock and exit-code files
 * per account), the same way as the marker: no link or directory is followed, and only that account's names are
 * touched. True when none remain.
 */
function pmssPikkumiesAskStateClear(string $username, string $stateDir = '/var/lib/pmss/pikkumies-ask'): bool
{
    if (!pmssValidateUsername($username) || is_link($stateDir) || !pmssPathTargetIsSafe($stateDir, true)) {
        return false;
    }
    $ok = true;
    foreach (['.out', '.in', '.lock', '.out.rc'] as $suffix) {
        $path = $stateDir.'/'.$username.$suffix;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            continue;
        }
        $type = $stat['mode'] & 0170000;
        $ok = (($type === 0100000 || $type === 0120000) && @unlink($path)) && $ok;
    }
    return $ok;
}

/**
 * Where a validated account's marker goes, creating the root-only state directory when it is missing, or null when the
 * name is invalid, the directory is a link or not a directory, or the marker already exists (a link included, so a
 * dangling link never becomes the target of a root-owned touch). The caller writes the marker only after the install
 * succeeded, so a failed install is tried again (ADR 0090).
 */
function pmssPikkumiesPreinstallStateMarkerPath(
    string $username,
    string $stateDir = '/var/lib/pmss/pikkumies-preinstall'
): ?string {
    if (!pmssValidateUsername($username) || is_link($stateDir) || !pmssPathTargetIsSafe($stateDir, true)) {
        return null;
    }
    if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
        return null;
    }
    $path = $stateDir.'/'.$username;
    return is_link($path) || file_exists($path) ? null : $path;
}
