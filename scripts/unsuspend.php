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
require_once __DIR__.'/lib/user/userConfigStore.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Unsuspending
// a user when /home is unavailable would fail or act on stale paths.
pmssRequireHomeMounted('unsuspend.php');

$usage = 'unsuspend.php USERNAME';
$username = $argv[1] ?? '';
if ($username === '') {
    die($usage."\n");
}

$normalizedUsername = pmssUsernameNormalizeIfValid($username);
// This script is invoked by operators/automation. Validate inputs early so we
// never feed garbage to usermod or log files.
if ($normalizedUsername === null) {
    $username = pmssNormalizeUsername($username);
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'unsuspend',
            'validate',
            $username,
            array(
                'status'  => 'ERR',
                'message' => 'Rejected username due to validation failure',
            )
        )
    );
    die("Invalid username: {$username}\n");
}
$username = $normalizedUsername;

$homeDir = "/home/{$username}";
$activeRoot = "$homeDir/www";
$disabledRoot = "$homeDir/www-disabled";

$hasSuspendedContent = static function (string $candidate): bool {
    if (!is_dir($candidate)) {
        return false;
    }
    if (is_dir($candidate.'/rutorrent') || is_file($candidate.'/index.php')) {
        return true;
    }
    $entries = @scandir($candidate);
    if ($entries === false) {
        return false;
    }
    $entries = array_values(array_diff($entries, array('.', '..')));
    if (empty($entries)) {
        return false;
    }
    $allowed = array('index.html', 'public');
    foreach ($entries as $entry) {
        if (!in_array($entry, $allowed, true)) {
            return true;
        }
    }
    $publicPath = $candidate.'/public';
    if (!is_dir($publicPath)) {
        return true;
    }
    $publicEntries = @scandir($publicPath);
    if ($publicEntries === false) {
        return true;
    }
    $publicEntries = array_values(array_diff($publicEntries, array('.', '..')));
    foreach ($publicEntries as $entry) {
        if ($entry !== 'index.html') {
            return true;
        }
    }
    return false;
};

$findSuspendedBackup = static function (string $homeDir) use ($hasSuspendedContent): ?string {
    $candidates = glob($homeDir.'/www-suspended-*', GLOB_NOSORT);
    if (!is_array($candidates) || empty($candidates)) {
        return null;
    }
    $ranked = array();
    foreach ($candidates as $candidate) {
        if (!$hasSuspendedContent($candidate)) {
            continue;
        }
        $mtime = @filemtime($candidate);
        $ranked[] = array(
            'path' => $candidate,
            'mtime' => ($mtime === false ? 0 : $mtime),
        );
    }
    if (empty($ranked)) {
        return null;
    }
    usort($ranked, static function (array $a, array $b): int {
        if ($a['mtime'] === $b['mtime']) {
            return strcmp($b['path'], $a['path']);
        }
        return ($b['mtime'] <=> $a['mtime']);
    });
    return $ranked[0]['path'];
};

$restoredFromBackup = false;
// Canonical suspended detection: only the presence of www-disabled matters.
// Recovery path: if both www-disabled and www are absent, restore a backup.
if (!is_dir($disabledRoot)) {
    if (!is_dir($activeRoot)) {
        $candidate = $findSuspendedBackup($homeDir);
        if ($candidate !== null && @rename($candidate, $activeRoot)) {
            echo "Notice: restored {$activeRoot} from {$candidate}\n";
            $restoredFromBackup = true;
        } elseif ($candidate !== null) {
            echo "Warning: failed to restore {$candidate}\n";
        }
    }
    if (!$restoredFromBackup) {
        (new UserConfigStore())->setSuspended($username, false);
        if (!is_dir($activeRoot)) {
            die("User is not suspended and no recoverable web root was found\n");
        }
        die("User is not suspended\n");
    }
}

pmssUserWriteLogs(
    pmssUserBaseContext(
        'unsuspend',
        'start',
        $username,
        array(
            'status'   => 'INFO',
            'home_dir' => $homeDir,
        )
    )
);

// Unlock and extend expiry before restoring services.
pmssUserLifecycleStep('unsuspend', $username, 'unlock_account', 'usermod -U '.escapeshellarg($username), false);
$farFuture = date('Y-m-d', time() + (60 * 60 * 24 * 365 * 10));
pmssUserLifecycleStep(
    'unsuspend',
    $username,
    'set_expiry',
    'usermod --expiredate '.escapeshellarg($farFuture).' '.escapeshellarg($username),
    false
);

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
(new UserConfigStore())->setSuspended($username, is_dir($disabledRoot));

pmssUserLifecycleStep(
    'unsuspend',
    $username,
    'refresh_nginx_config',
    'php /scripts/util/createNginxConfig.php --user '.escapeshellarg($username),
    false
);

// Prefer systemd when available but keep init.d fallback for older hosts.
$restartRc = pmssUserLifecycleStep(
    'unsuspend',
    $username,
    'restart_nginx_systemctl',
    'systemctl restart nginx',
    false
);
if ($restartRc !== 0) {
    pmssUserLifecycleStep(
        'unsuspend',
        $username,
        'restart_nginx_init',
        '/etc/init.d/nginx restart',
        false
    );
}

pmssUserWriteLogs(
    pmssUserBaseContext(
        'unsuspend',
        'start_rtorrent',
        $username,
        array(
            'status'   => 'INFO',
            'home_dir' => $homeDir,
        )
    )
);
pmssUserLifecycleStep('unsuspend', $username, 'start_rtorrent', '/scripts/startRtorrent '.escapeshellarg($username), false);
