#!/usr/bin/php
<?php
/**
 * Restore access for a previously suspended account.
 *
 * Re-enables the Unix account, restores the original web root from
 * /home/<user>/www-disabled when present, and restarts rTorrent for the user.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */
require_once __DIR__.'/lib/userLifecycle.php';

$usage = 'unsuspend.php USERNAME';
$username = $argv[1] ?? '';
if ($username === '') {
    die($usage."\n");
}

if (!pmssValidateUsername($username)) {
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

$homeDir = "/home/{$username}";
$activeRoot = "$homeDir/www";
$disabledRoot = "$homeDir/www-disabled";
$marker = $activeRoot.'/.pmss-suspended';

$hasPlaceholder = file_exists($marker);
if (!is_dir($disabledRoot) && !$hasPlaceholder) {
    die("User is not suspended\n");
}

passthru('usermod -U '.escapeshellarg($username));
$farFuture = date('Y-m-d', time() + (60 * 60 * 24 * 365 * 10));
passthru('usermod --expiredate '.escapeshellarg($farFuture).' '.escapeshellarg($username));

if ($hasPlaceholder) {
    pmssRemoveSuspendedLanding($activeRoot);
}

if (is_dir($disabledRoot)) {
    // Handle legacy conflicts where a non-placeholder www/ directory exists
    // alongside www-disabled/. This can happen when newer scripts created a
    // stub docroot for a suspended user. Preserve any unexpected content by
    // moving it aside before restoring the original web root.
    if (is_dir($activeRoot) && !$hasPlaceholder) {
        $backup = $homeDir.'/www-conflicting-'.date('YmdHis');
        if (@rename($activeRoot, $backup)) {
            echo "Notice: moved existing {$activeRoot} to {$backup} before restore\n";
        } else {
            echo "Warning: existing {$activeRoot} may prevent restore; please inspect and clean up manually\n";
        }
    }

    if (!@rename($disabledRoot, $activeRoot)) {
        echo "Warning: failed to restore {$disabledRoot}\n";
    }
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

/**
 * Delete the placeholder landing page tree.
 */
function pmssRemoveSuspendedLanding(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory.'/'.$item;
        if (is_dir($path)) {
            pmssRemoveSuspendedLanding($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}
