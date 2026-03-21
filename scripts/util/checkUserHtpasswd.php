#!/usr/bin/env php
<?php
/**
 * Synchronise legacy global lighttpd htpasswd entries to per-user instances.
 *
 * Intended for transitional environments where a global /etc/lighttpd/.htpasswd
 * file may still contain valid credentials. Modern deployments should favour
 * per-user auth from the start.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
// TODO Check if this is still used, since transition happened years ago

// Some kind of htpasswd synchronization from times when lighttpd global instance transition to per-user instances

require_once __DIR__.'/../lib/userLifecycle.php';
if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) {
    require_once $pmssUserLogPath;
}

$argUserRaw = trim((string) ($argv[1] ?? ''));
if ($argUserRaw !== '') {
    $argUser = function_exists('pmssNormalizeUsername')
        ? pmssNormalizeUsername($argUserRaw)
        : $argUserRaw;
    if (
        $argUser !== $argUserRaw
        || (function_exists('pmssValidateUsername') && !pmssValidateUsername($argUser))
    ) {
        fwrite(STDERR, "Invalid username\n");
        exit(1);
    }
    if (function_exists('posix_getpwnam') && posix_getpwnam($argUser) === false) {
        fwrite(STDERR, "User not found\n");
        exit(1);
    }
    $users = [$argUser];
} else {
    $usersRaw = trim((string)shell_exec('/scripts/listUsers.php'));
    if ($usersRaw === '') {
        die("No users setup - nothing to do\n");
    }
    $users = array_filter(array_map('trim', explode("\n", $usersRaw)), 'strlen');
}

$globalHtpasswd = '/etc/lighttpd/.htpasswd';
$globalContents = @file_get_contents($globalHtpasswd);
if ($globalContents === false || trim($globalContents) === '') {
    if ($argUserRaw === '') {
        echo "Global htpasswd file missing or empty, skipping synchronization\n";
    }
    exit(0);
}

$passwords = array_filter(explode("\n", $globalContents), 'strlen');

foreach ($users as $thisUser) {
    if (!pmssValidateUsername($thisUser)) {
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'htpasswd',
                'validate',
                $thisUser,
                [
                    'status'  => 'ERR',
                    'message' => 'Skipping invalid username in checkUserHtpasswd',
                ]
            )
        );
        continue;
    }

    $userHtpasswd = "/home/{$thisUser}/.lighttpd/.htpasswd";
    if (
        is_file($userHtpasswd)
        && ($userHtpasswdContents = @file_get_contents($userHtpasswd)) !== false
        && strpos($userHtpasswdContents, $thisUser) !== false
    ) {
        continue;
    }

    foreach ($passwords as $thisPassword) {
        if (strpos($thisPassword, $thisUser.':') === 0) {
            file_put_contents($userHtpasswd, $thisPassword."\n", FILE_APPEND);
            pmssUserLifecycleStep(
                'htpasswd',
                $thisUser,
                'chown_htpasswd',
                'chown '.escapeshellarg($thisUser.':'.$thisUser).' '.escapeshellarg($userHtpasswd),
                false
            );
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'htpasswd sync: appended legacy credential to per-user .htpasswd');
            }
        }
    }
}
