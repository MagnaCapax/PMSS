<?php
/** Decide whether a billing identity file may be repaired by userPermissions.php. */

/**
 * Accept only a root-owned regular entry. The caller supplies lstat() metadata so
 * symlinks are never dereferenced and one decision gates both mode and owner repair.
 */
function pmssUserBillingFileRepairable(string $user, string $path, $stat): bool
{
    if (!is_array($stat)) {
        return false;
    }

    $name = basename($path);
    if (($stat['mode'] & 0170000) !== 0100000) {
        pmssUserLog($user, "userPermissions: {$name} is not a regular file; left as-is");
        return false;
    }

    if ($stat['uid'] !== 0) {
        pmssUserLog($user, sprintf(
            'userPermissions: %s owner uid=%d; left unadopted because readers require a root-owned file; authoritative writer: scripts/util/writeHomeMarker.php',
            $name,
            $stat['uid']
        ));
        return false;
    }

    return true;
}
