#!/usr/bin/php
<?php
# PMSS
# Copyright (C) Magna Capax Finland Oy 2010-2023
#TODO Check if this is still used, since transition happened years ago

//Some kind of htpasswd synchronization from times when lighttpd global instance transition to per user instances

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
    if ($thisUser === '') { continue; }
    $thisUserDir = "/home/{$thisUser}";
    if (file_exists($thisUserDir . '/.lighttpd/.htpasswd')) {
        $userHtpasswdContents = @file_get_contents($thisUserDir . '/.lighttpd/.htpasswd');
        if ($userHtpasswdContents !== false && strpos($userHtpasswdContents, $thisUser) !== false) continue;   // Already exists! :)
    }

    foreach ($passwords as $thisPassword) {
        if (strpos($thisPassword, $thisUser.':') === 0) {
            file_put_contents($thisUserDir . '/.lighttpd/.htpasswd', $thisPassword."\n", FILE_APPEND);
            passthru("chown {$thisUser}.{$thisUser} {$thisUserDir}/.lighttpd/.htpasswd");
        }
    }
}
