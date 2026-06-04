#!/usr/bin/env php
<?php
/**
 * Restore access for a previously suspended account.
 *
 * Re-enables the Unix account, restores the original web root from
 * /home/<user>/www-disabled when present, refreshes nginx user config, and
 * restarts rTorrent for the user.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/homeMount.php';
require_once __DIR__.'/lib/lighttpd/htpasswd.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Unsuspending
// a user when /home is unavailable would fail or act on stale paths.
pmssRequireHomeMounted('unsuspend.php');

['username' => $username, 'homeDir' => $homeDir, 'activeRoot' => $activeRoot, 'disabledRoot' => $disabledRoot] = pmssUserLifecycleRequireUserRoots($argv, 'unsuspend.php', 'unsuspend');

$restoredFromBackup = false;
// Canonical suspended detection: only the presence of www-disabled matters.
// Recovery path: if both www-disabled and www are absent, restore a backup.
if (!is_dir($disabledRoot)) {
    if (!is_dir($activeRoot)) {
        $candidate = pmssUserLifecycleFindSuspendedBackup($homeDir);
        if ($candidate !== null && @rename($candidate, $activeRoot)) {
            echo "Notice: restored {$activeRoot} from {$candidate}\n";
            $restoredFromBackup = true;
        } elseif ($candidate !== null) {
            echo "Warning: failed to restore {$candidate}\n";
        }
    }
    if (!$restoredFromBackup) {
        pmssUserLifecycleSetSuspendedState($username, false);
        if (!is_dir($activeRoot)) {
            die("User is not suspended and no recoverable web root was found\n");
        }
        die("User is not suspended\n");
    }
}

pmssUserLifecycleContextLogHomeInfo('unsuspend', 'start', $username, $homeDir);

// Unlock and extend expiry before restoring services.
$farFuture = date('Y-m-d', time() + (60 * 60 * 24 * 365 * 10));
pmssUserLifecycleRunSteps('unsuspend', $username, array(
    array('unlock_account', 'usermod -U '.escapeshellarg($username)),
    array('set_expiry', 'usermod --expiredate '.escapeshellarg($farFuture).' '.escapeshellarg($username)),
), false);

$htpasswdSynced = pmssUserHtpasswdSyncFromShadow($username);
pmssUserLifecycleContextLogStatusMessage(
    'unsuspend',
    'sync_htpasswd_shadow',
    $username,
    $htpasswdSynced ? 'OK' : 'WARN',
    $htpasswdSynced
        ? 'Resynced per-user htpasswd from unlocked shadow hash'
        : 'Unable to resync per-user htpasswd from unlocked shadow hash'
);
if (!$htpasswdSynced) {
    echo "Warning: unable to resync per-user htpasswd from unlocked shadow hash\n";
}

// Preserve any unexpected www/ content created during suspension (or by legacy
// scripts) before restoring the original web root.
if (is_dir($activeRoot) && !$restoredFromBackup && is_dir($disabledRoot)) {
    $backup = $homeDir.'/www-suspended-'.date('YmdHis');
    if (@rename($activeRoot, $backup)) {
        echo "Notice: moved existing {$activeRoot} to {$backup} before restore\n";
    } else {
        echo "Warning: existing {$activeRoot} may prevent restore; please inspect and clean up manually\n";
    }
}

if (is_dir($disabledRoot) && !@rename($disabledRoot, $activeRoot)) {
    echo "Warning: failed to restore {$disabledRoot}\n";
}
// Best-effort: mirror the state in the user config store (marker is canonical).
pmssUserLifecycleSyncSuspendedState($username, $disabledRoot);
pmssUserLifecycleRefreshManagedNginxConfig('unsuspend', $username, false);

pmssUserLifecycleContextLogHomeInfo('unsuspend', 'start_rtorrent', $username, $homeDir);
pmssUserLifecycleStep('unsuspend', $username, 'start_rtorrent', '/scripts/startRtorrent '.escapeshellarg($username), false);
