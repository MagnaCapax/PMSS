#!/usr/bin/env php
<?php
/**
 * Restore access for a previously suspended account.
 *
 * Re-enables the Unix account, restores the original web root from
 * /home/<user>/www-disabled when present, refreshes nginx user config, and
 * restarts and verifies the per-user web stack before completing.
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
['activeRootExists' => $activeRootExists, 'disabledRootExists' => $disabledRootExists] = pmssUserLifecycleRequireWebRootState('unsuspend', $username, $homeDir, $activeRoot, $disabledRoot);

$restoredFromBackup = false;
// Canonical suspended detection: only the presence of www-disabled matters.
// Recovery path: if both www-disabled and www are absent, restore a backup.
if (!$disabledRootExists) {
    if (!$activeRootExists) {
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

// Never replace a valid active root with an empty restore source. This state is
// produced when suspend.php cannot archive www but still leaves its marker.
if ($disabledRootExists && !pmssUserLifecycleWebRootContainsUserContent($disabledRoot)) {
    $activeRootHasContent = $activeRootExists
        && pmssUserLifecycleWebRootContainsUserContent($activeRoot);
    if (!$activeRootHasContent) {
        pmssUserLifecycleContextLogStatusMessage(
            'unsuspend',
            'validate_restore_source',
            $username,
            'ERR',
            'Suspended web root contains no recoverable user content',
            array('disabled_root' => $disabledRoot)
        );
        die("Error: suspended web root contains no recoverable user content\n");
    }

    if (!pmssUserLifecycleClearEmptySuspendedMarker($homeDir, $disabledRoot)) {
        pmssUserLifecycleContextLogStatusMessage(
            'unsuspend',
            'validate_restore_source',
            $username,
            'ERR',
            'Unable to clear empty suspended web root marker',
            array('disabled_root' => $disabledRoot)
        );
        die("Error: unable to clear empty suspended web root marker\n");
    }

    $disabledRootExists = false;
    pmssUserLifecycleContextLogStatusMessage(
        'unsuspend',
        'validate_restore_source',
        $username,
        'WARN',
        'Ignored empty suspended web root marker and kept active web root',
        array('disabled_root' => $disabledRoot)
    );
    echo "Warning: ignored empty suspended web root marker; kept {$activeRoot}\n";
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
if ($activeRootExists && !$restoredFromBackup && $disabledRootExists) {
    $backup = $homeDir.'/www-suspended-'.date('YmdHis');
    if (@rename($activeRoot, $backup)) {
        echo "Notice: moved existing {$activeRoot} to {$backup} before restore\n";
        $activeRootExists = false;
    } else {
        echo "Warning: existing {$activeRoot} may prevent restore; please inspect and clean up manually\n";
    }
}

if ($disabledRootExists && !$activeRootExists && !@rename($disabledRoot, $activeRoot)) {
    echo "Warning: failed to restore {$disabledRoot}\n";
}

if (!is_file($activeRoot.'/index.php')) {
    pmssUserLifecycleContextLogStatusMessage(
        'unsuspend',
        'validate_restored_web_root',
        $username,
        'ERR',
        'Restored web root is missing index.php',
        array('active_root' => $activeRoot)
    );
    fwrite(STDERR, "Error: restored web root is missing index.php\n");
    exit(1);
}

// Best-effort: mirror the state in the user config store (marker is canonical).
pmssUserLifecycleSyncSuspendedState($username, $disabledRoot);
pmssUserLifecycleRefreshManagedNginxConfig('unsuspend', $username, false);
$webStackRc = pmssUserLifecycleWebStackStartAndVerify('unsuspend', $username, false);

pmssUserLifecycleContextLogHomeInfo('unsuspend', 'start_rtorrent', $username, $homeDir);
pmssUserLifecycleStep('unsuspend', $username, 'start_rtorrent', '/scripts/startRtorrent '.escapeshellarg($username), false);
if ($webStackRc !== 0) {
    fwrite(STDERR, "Error: per-user web stack failed to start\n");
    exit(1);
}
