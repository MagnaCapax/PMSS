<?php
/** Ordered shell-step planning for bounded cleanup and synchronous account purges.
 * Loaded by terminationCleanup.php; public entry points retain their contracts.
 * @license GPL-3.0-only
 */

/** Guard account-controlled socket removal and keep the inner rm exit status. */
function pmssTerminateUserAccountSocketRemoveCommand(string $username, string $path): string
{
    $quoted = escapeshellarg($path);
    $command = 'if [ -e '.$quoted.' ] || [ -L '.$quoted.' ]; then rm -rf -- '.$quoted.'; fi';
    return 'timeout --kill-after=5s 45s '.pmssBuildUserShellNoPamCommand($username, $command);
}

/** Build the pre-userdel steps as data so their order can be verified. */
function pmssTerminateUserInitialRemovalSteps(string $username, int $uid): array
{
    // Validate before building either command; socket cleanup still runs under the account UID.
    if (!pmssUsernameIsValid($username) || $uid < 1) {
        throw new InvalidArgumentException('Refusing unsafe account socket cleanup');
    }
    return array(
        array('crontab_remove', 'crontab -r -u '.escapeshellarg($username).' || true'),
        array('crontab_spool_remove', 'rm -f -- '.escapeshellarg('/var/spool/cron/crontabs/'.$username).' '.escapeshellarg('/var/spool/cron/'.$username).' || true'),
        array('remove_tmux_socket', pmssTerminateUserAccountSocketRemoveCommand($username, '/tmp/tmux-'.$uid)),
        array('remove_screen_socket', pmssTerminateUserAccountSocketRemoveCommand($username, '/var/run/screen/S-'.$username)),
        array('userdel_initial', 'userdel '.escapeshellarg($username)),
    );
}

/** Build the final process and user manager cleanup in execution order. */
function pmssTerminateUserFinalRemovalSteps(string $username, int $uid, string $killUserCommand): array
{
    if (!pmssUsernameIsValid($username)) {
        throw new InvalidArgumentException('Refusing unsafe final account cleanup');
    }
    $userdel = 'userdel '.escapeshellarg($username);
    return array(
        array('kill_processes_final', $killUserCommand),
        array('reset_failed_user_manager', pmssTerminateUserResetFailedCommand($uid)),
        array('userdel_groupdel_final', $userdel),
        array('groupdel_final', 'groupdel '.escapeshellarg($username)),
        array('cleanup_lock_files', 'rm -f -- '.pmssRuntimeLockDir().'/pmss-*-'.$username.'.lock /run/lock/pmss-*-'.$username.'.lock /tmp/pmss-*-'.$username.'.lock'),
    );
}

/** Wait for the manager to become inactive before clearing failed state. */
function pmssTerminateUserResetFailedCommand(int $uid): string
{
    if ($uid < 1) {
        throw new InvalidArgumentException('Refusing unsafe user manager UID');
    }
    $unit = escapeshellarg('user@'.$uid.'.service');
    return 'for attempt in 1 2 3 4 5 6 7 8 9 10; do '
        .'state=$(timeout --kill-after=1s 5s systemctl is-active '.$unit.' 2>/dev/null); '
        .'rc=$?; if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then exit "$rc"; fi; '
        .'case "$state" in active|activating|deactivating|reloading) sleep 0.5;; *) break;; esac; '
        .'done; case "$state" in active|activating|deactivating|reloading) exit 1;; esac; '
        .'if timeout --kill-after=5s 45s systemctl is-failed --quiet '.$unit.'; '
        .'then timeout --kill-after=5s 45s systemctl reset-failed '.$unit.'; '
        .'else rc=$?; if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then exit "$rc"; fi; fi';
}

/**
 * Steps that purge a directory: remove ordinary files, clear immutable flags
 * through verified open inodes on whatever survived, then remove that residue.
 *
 * The ordering is deliberate (Refs #606): a full recursive chattr BEFORE the removal
 * delays inode recovery on large accounts even when nothing is immutable. Running the scan
 * only on the residue keeps the walk bounded to the files rm could not delete, and
 * covers every immutable path in the tree without a list that can drift from what
 * the writers actually mark.
 */
function pmssTerminateUserPurgeDirectorySteps(string $label, string $path): array
{
    // Accept only the exact account paths, including recreate's fixed archive suffix.
    $prefix = $label === 'home' ? '/home/' : '/home/backup-';
    $suffix = $label === 'user_backup_superseded' ? '.superseded' : '';
    $validLabel = in_array($label, array('home', 'user_backup', 'user_backup_superseded'), true);
    $username = substr($path, strlen($prefix));
    if ($suffix !== '' && substr($username, -strlen($suffix)) === $suffix) {
        $username = substr($username, 0, -strlen($suffix));
    } elseif ($suffix !== '') {
        $username = '';
    }
    if (!$validLabel || strpos($path, $prefix) !== 0 || !pmssUsernameIsValid($username)
        || $path !== $prefix.$username.$suffix) {
        throw new InvalidArgumentException('Refusing unsafe user purge path');
    }

    $remove = pmssPinnedTreePurgeCommand($path, false);

    return array(
        array('remove_'.$label.'_initial', $remove),
        array('clear_immutable_'.$label, pmssPinnedTreePurgeCommand($path, true)),
        array('remove_'.$label.'_leftovers', $remove),
    );
}
