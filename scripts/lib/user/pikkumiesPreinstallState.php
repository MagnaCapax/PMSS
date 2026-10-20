<?php
/** Root-owned Pikkumies preinstall marker cleanup for account lifecycle flows. */

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
