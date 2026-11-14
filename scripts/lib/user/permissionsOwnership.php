<?php
/** Ownership selection, pruning and subordinate-ID preservation. */

require_once __DIR__.'/subordinateIds.php';

/** Run the home walk without visiting app-managed or bulk data trees. */
function pmssUserPermissionsOwnerWalk(string $thisUser, array $userIds, array $trafficPaths): void
{
    // Targeted chown of the home tree excluding known root-owned paths, app-managed
    // trees, and bulk payload data. Healthy homes should not pay a blanket chown
    // cost when uid/gid ownership is already correct.
    $excludes = [
        "/home/{$thisUser}/.quota",
        "/home/{$thisUser}/.resourceData",
        // trafficLimits writes it as root and the FireQOS renderer trusts only a root-owned copy;
        // never chown it to the account, and never adopt a tenant-written one (#1056).
        "/home/{$thisUser}/.throttle",
        "/home/{$thisUser}/.billingServiceId",
        "/home/{$thisUser}/.billingId",
        "/home/{$thisUser}/.billingClientId",
        "/home/{$thisUser}/.notifyEmail",
        $trafficPaths['normal'],
        $trafficPaths['local'],
        $trafficPaths['ingress'],
        $trafficPaths['ingressLocal'],
        "/home/{$thisUser}/.rtorrent.rc",
        "/home/{$thisUser}/www/rutorrent/conf/config.php",
        "/home/{$thisUser}/.bashrc",
        "/home/{$thisUser}/.profile",
        "/home/{$thisUser}/.lighttpd.conf",
    ];
    $findParts = [sprintf('find %s -mindepth 1', escapeshellarg("/home/{$thisUser}"))];
    // Prune ~/.local subtree to avoid noisy chown failures on application-managed trees (e.g. Docker).
    $findParts[] = '-path '.escapeshellarg("/home/{$thisUser}/.local").' -prune -o';
    // Bulk data files are user payload and typically already owned correctly; avoid
    // walking that whole subtree during routine permission refreshes.
    $findParts[] = '-path '.escapeshellarg("/home/{$thisUser}/data").' -prune -o';
    foreach ($excludes as $ex) {
        $findParts[] = '-not -path '.escapeshellarg($ex);
    }
    // Skip symbolic links so broken symlinks (e.g. ~/www/watch) do not cause chown
    // dereference errors or non-zero rc noise in logs.
    $findParts[] = '-not -type l \( ! -type f -o -links 1 \)';
    $uidRanges = pmssSubordinateIdRanges($thisUser, (int) $userIds['uid'], 'uid');
    $gidRanges = pmssSubordinateIdRanges($thisUser, (int) $userIds['gid'], 'gid');
    if ($uidRanges !== [] || $gidRanges !== []) {
        pmssUserLog($thisUser, sprintf(
            'userPermissions: honoured %d subordinate uid ranges and %d subordinate gid ranges',
            count($uidRanges),
            count($gidRanges)
        ));
    }
    pmssUserPermissionsRun(implode(' ', $findParts).sprintf(' \\( -not %s -o -not %s \\) -execdir chown -h %s {} +',
        pmssOwnerIdSetFindPredicate('-uid', (int) $userIds['uid'], $uidRanges),
        pmssOwnerIdSetFindPredicate('-gid', (int) $userIds['gid'], $gidRanges),
        escapeshellarg($userIds['uid'].':'.$userIds['gid'])));
}
