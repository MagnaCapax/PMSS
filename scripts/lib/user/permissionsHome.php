<?php
/** Repair the home owner and prepare the two account executable directories. */

/** Called under the CLI lock, before traffic immutability changes. */
function pmssUserPermissionsHomePrepare(string $thisUser, string $homeDir, array $userIds): void
{
    // Safer traversal without relying on xargs delimiters; applies to each directory in place.
    // Skip ~/.local entirely to avoid interfering with per-user application data (e.g. Docker overlays).
    $homeOwner = @fileowner($homeDir);
    $homeGroup = @filegroup($homeDir);
    if ($homeOwner !== false && $homeGroup !== false &&
        ($homeOwner !== $userIds['uid'] || $homeGroup !== $userIds['gid'])) {
        fwrite(
            STDERR,
            sprintf(
                "[WARN] Fixing home directory ownership for %s (uid=%s gid=%s expected uid=%s gid=%s)\n",
                $homeDir,
                $homeOwner,
                $homeGroup,
                $userIds['uid'],
                $userIds['gid']
            )
        );
        pmssUserPermissionsOwnerApply($homeDir, $homeDir, $userIds['uid'].':'.$userIds['gid']);
        pmssUserLog(
            $thisUser,
            sprintf(
                'userPermissions: fixed home ownership (uid=%s gid=%s, was uid=%s gid=%s)',
                $userIds['uid'],
                $userIds['gid'],
                $homeOwner,
                $homeGroup
            )
        );
    }

    // Ensure ~/.bin and ~/bin exist with safe permissions and ownership.
    foreach ([
        ["/home/{$thisUser}/.bin", 'userPermissions: created ~/.bin with safe ownership'],
        ["/home/{$thisUser}/bin", 'userPermissions: created ~/bin with safe ownership'],
    ] as $binSpec) {
        $binDir = $binSpec[0];
        if (is_dir($binDir)) {
            continue;
        }
        if (pmssAccountPathRun($thisUser, $homeDir, [$binDir], 'mkdir -m 750 -- '.escapeshellarg($binDir))
            || pmssEnsureUserHomeDir($thisUser, $homeDir, basename($binDir), 0750)) {
            pmssUserLog($thisUser, $binSpec[1]);
        }
    }
}
