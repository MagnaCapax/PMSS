<?php
/**
 * Explicit payload-owner repair using the existing permission traversal guards.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/permissionsApply.php';
require_once __DIR__.'/subordinateIds.php';

/** Build a data-only repair; subordinate UID ranges must come from the account allocation. */
function pmssUserDataOwnershipRepairCommand(string $home, int $uid, int $gid, array $uidRanges = []): ?string
{
    $data = $home.'/data';
    if ($uid <= 0 || $gid <= 0 || !pmssPathAbsoluteStringIsSafe($home)
        || !pmssPathTargetIsSafe($data, true) || !is_dir($data)) {
        return null;
    }

    // Keep container owners, links and multiply linked files; never cross a mount.
    $tests = '-xdev -not -type l \\( ! -type f -o -links 1 \\) -not '
        .pmssOwnerIdSetFindPredicate('-uid', $uid, $uidRanges);
    $command = pmssUserPermissionsAnchoredFind(
        $home,
        $data,
        true,
        $tests,
        '-execdir /usr/bin/chown -h '.escapeshellarg($uid.':'.$gid).' {} +'
    );

    // execdir must never resolve commands through an account-controlled PATH.
    return $command === null ? null : '/usr/bin/env PATH=/usr/sbin:/usr/bin:/sbin:/bin '.$command;
}
