#!/usr/bin/env php
<?php
/*
Pulsed Media Seedbox Management Software "PMSS"
This script manages and monitors user-specific lighttpd and php-cgi processes.
*/

$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
require_once __DIR__.'/../lib/userLifecycle.php';

// Get & parse users list (optionally for a single user).
$argUserRaw = isset($argv[1]) ? trim((string)$argv[1]) : '';
$singleUserMode = ($argUserRaw !== '');
if (!$singleUserMode) {
    echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";
}
if ($singleUserMode) {
    $argUser = pmssNormalizeUsername($argUserRaw);
    if ($argUser !== $argUserRaw || !pmssValidateUsername($argUser)) {
        fwrite(STDERR, "Invalid username\n");
        exit(1);
    }
    if (function_exists('posix_getpwnam') && posix_getpwnam($argUser) === false) {
        fwrite(STDERR, "User not found\n");
        exit(1);
    }
    $users = [$argUser];
} else {
    $users = explode("\n", trim((string) shell_exec('/scripts/listUsers.php')));
}

$startLighttpd = static function (string $user): void {
    echo "Start lighttpd for user: {$user}\n";
    passthru('/scripts/startLighttpd ' . $user);
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'lighttpd start requested');
    }
};

$restartLighttpd = static function (string $user) use ($startLighttpd): void {
    echo "Killing (if any) lighttpd for user: {$user}\n";
    shell_exec("killall -15 -u {$user} lighttpd; killall -15 -u {$user} php-cgi; sleep 5; killall -9 -u {$user} lighttpd; killall -9 -u {$user} php-cgi;");
    usleep(50000);   // brief pause before relaunch
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'lighttpd restart requested');
    }
    $startLighttpd($user);
};

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    $thisUser = trim($thisUser);
    if ($thisUser === '') continue;
    $normalizedUser = pmssNormalizeUsername($thisUser);
    if ($normalizedUser !== $thisUser || !pmssValidateUsername($thisUser)) {
        echo "Skipping invalid username: {$thisUser}\n";
        continue;
    }
    #TODO Uh Oh next one should be separate script :) This is separate task altogether. Works here too as expected, just a bit confusing
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'lighttpd stopped due to suspension');
            }
            continue;  //Suspended
    }

    $instancesLighttpd = shell_exec("pgrep -u {$thisUser} lighttpd");
    $instancesPhpCgi = shell_exec("pgrep -u {$thisUser} php-cgi");
    $socketError = false;

    // If socket connection fails or no php-cgi instance is found
    if (empty($instancesPhpCgi)) {        
            echo "php-cgi not running, for user: {$thisUser}. Killing lighttpd instances.\n";
            $restartLighttpd($thisUser);
            continue;

    }

    // Connect to each php-cgi socket to verify it responds.
    // Increment the loop bound if php.max-procs is greater than one.
    for ($i = 0; $i < 1; $i++) {
        $socket = fsockopen("unix:///home/{$thisUser}/.lighttpd/php.socket-$i", 0, $errno, $errstr, 1);
        if (!$socket or $errno or $errstr) {        
            echo "Error when attempting to connect to socket /home/{$thisUser}/.lighttpd/php.socket-$i: {$errno}, {$errstr}\n";
            echo "php-cgi, for user: {$thisUser}. Killing lighttpd instances.\n";            
            $socketError = true;
            break;
        }
    }
    if ($socketError == true) { $restartLighttpd($thisUser); continue; }



    // Let's actually test we get 401 auth requested!
    /* temp disabled, so much log spam and did not achieve desired results.
    $curl = curl_init("http://127.0.0.1/user-{$thisUser}/");
    curl_setopt( $curl, CURLOPT_HEADER, true);
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true);
    $httpResponse = curl_exec( $curl );

   if (strpos($httpResponse, 'HTTP/1.1 401 Unauthorized') === false) $instancesLighttpd = '';
    */

    if(empty($instancesLighttpd)) {    // No instances at all? Ok time to start Lighttpd!
        $startLighttpd($thisUser);
        continue;
    }

}
