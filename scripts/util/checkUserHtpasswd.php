#!/usr/bin/php
<?php
//Some kind of htpasswd synchronization from times when lighttpd global instance transition to per user instances

$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
if (count($users) == 0) die("No users setup - nothing to do\n");

foreach($users AS $thisUser) {
    $thisUserDir = "/home/{$thisUser}";
    if (file_exists($thisUserDir . '/.lighttpd/.htpasswd')) {
        $userHtpasswdContents = file_get_contents($thisUserDir . '/.lighttpd/.htpasswd');
        if (strpos($userHtpasswdContents, $thisUser) !== false) continue;   // Already exists! :)
    }
    
    $htpasswdGlobalContents = @file_get_contents('/etc/lighttpd/.htpasswd');
    $passwords = explode("\n", $htpasswdGlobalContents);
    foreach ($passwords AS $thisPassword) {
        if (strpos($thisPassword, $thisUser) === 0) {
            file_put_contents($thisUserDir . '/.lighttpd/.htpasswd', $thisPassword, FILE_APPEND);       // There we go :)
            passthru("chown {$thisUser}.{$thisUser} {$thisUserDir}/.lighttpd/.htpasswd");
        }
    
    }
}

