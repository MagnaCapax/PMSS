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
 */
// TODO Check if this is still used, since transition happened years ago

// Some kind of htpasswd synchronization from times when lighttpd global instance transition to per-user instances

require_once __DIR__.'/../lib/userLifecycle.php';

$usersRaw = trim((string)shell_exec('/scripts/listUsers.php'));
if ($usersRaw === '') {
    die("No users setup - nothing to do\n");
}

$users = array_filter(explode("\n", $usersRaw), 'strlen');
if (empty($users)) {
    die("No users setup - nothing to do\n");
}

$globalHtpasswd = '/etc/lighttpd/.htpasswd';
$globalContents = @file_get_contents($globalHtpasswd);
if ($globalContents === false || trim($globalContents) === '') {
    echo "Global htpasswd file missing or empty, skipping synchronization\n";
    exit(0);
}

$passwords = array_filter(explode("\n", $globalContents), 'strlen');

foreach ($users as $thisUser) {
    #TODO(user-logs): log per-user htpasswd sync operations to /var/log/pmss/user-<username>.log
    $thisUser = trim($thisUser);
    if ($thisUser === '') {
        continue;
    }
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

    $thisUserDir = "/home/{$thisUser}";
    if (file_exists($thisUserDir . '/.lighttpd/.htpasswd')) {
        $userHtpasswdContents = @file_get_contents($thisUserDir . '/.lighttpd/.htpasswd');
        if ($userHtpasswdContents !== false && strpos($userHtpasswdContents, $thisUser) !== false) continue;   // Already exists! :)
    }

    foreach ($passwords as $thisPassword) {
        if (strpos($thisPassword, $thisUser.':') === 0) {
            file_put_contents($thisUserDir . '/.lighttpd/.htpasswd', $thisPassword."\n", FILE_APPEND);
            pmssUserLifecycleStep(
                'htpasswd',
                $thisUser,
                'chown_htpasswd',
                'chown '.escapeshellarg($thisUser.':'.$thisUser).' '.escapeshellarg($thisUserDir.'/.lighttpd/.htpasswd'),
                false
            );
        }
    }
}
