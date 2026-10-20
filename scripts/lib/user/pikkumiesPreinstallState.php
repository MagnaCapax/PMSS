<?php
/** Root-owned Pikkumies preinstall markers: the path a marker is written to, and its cleanup for account lifecycle flows. */

require_once __DIR__.'/identity.php';

/** Clear a validated account's marker without traversing a link or directory. */
function pmssPikkumiesPreinstallStateClear(
    string $username,
    string $stateDir = '/var/lib/pmss/pikkumies-preinstall'
): bool {
    if (!pmssValidateUsername($username) || is_link($stateDir)
        || (file_exists($stateDir) && !is_dir($stateDir))) {
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
 * Where a validated account's marker goes, creating the root-only state directory when it is missing, or null when the
 * name is invalid, the directory is a link or not a directory, or the marker already exists (a link included, so a
 * dangling link never becomes the target of a root-owned touch). The caller writes the marker only after the install
 * succeeded, so a failed install is tried again (ADR 0090).
 */
function pmssPikkumiesPreinstallStateMarkerPath(
    string $username,
    string $stateDir = '/var/lib/pmss/pikkumies-preinstall'
): ?string {
    if (!pmssValidateUsername($username) || is_link($stateDir)) {
        return null;
    }
    if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
        return null;
    }
    $path = $stateDir.'/'.$username;
    return is_link($path) || file_exists($path) ? null : $path;
}
