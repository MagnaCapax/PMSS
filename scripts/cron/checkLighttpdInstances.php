#!/usr/bin/env php
<?php
/**
 * Cron task: check Lighttpd Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
/*
Pulsed Media Seedbox Management Software "PMSS"
This script manages and monitors user-specific lighttpd and php-cgi processes.
*/

require_once __DIR__.'/../lib/lighttpd/userConfigApply.php';
require_once __DIR__.'/../lib/userLifecycle.php';

// Get & parse users list (optionally for a single user).
$argUserRaw = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($argUserRaw === '') {
    echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";
}
$selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $argUserRaw, array('lookupMode' => 'account', 'strictInput' => true));
if ($selection['exitCode'] !== 0) exit($selection['exitCode']);
$users = $selection['users'];
foreach($users AS $thisUser) {
    $homeDir = "/home/{$thisUser}";
    #TODO Uh Oh next one should be separate script :) This is separate task altogether. Works here too as expected, just a bit confusing
    if (pmssUserWatchdogHandleSuspended($thisUser, ['lighttpd', 'php-cgi'], 'lighttpd stopped due to suspension')) continue;  //Suspended

    // Auto-generate lighttpd config if user has a home directory but missing config.
    // This catches migrated users whose data was transferred but config wasn't generated.
    // See: GH #180 — Migration target server PMSS config not generated
    if (!file_exists($homeDir.'/.lighttpd.conf') && is_dir($homeDir)) {
        echo "Config missing for user: {$thisUser} — generating\n";
        passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
        pmssUserLog($thisUser, 'lighttpd config generated (missing config detected)');
    }

    $instancesLighttpd = shell_exec("pgrep -u {$thisUser} lighttpd");
    $instancesPhpCgi = shell_exec("pgrep -u {$thisUser} php-cgi");
    $socketError = false;
    $socketPaths = pmssLighttpdWatchdogSocketPaths($homeDir, $homeDir.'/.lighttpd.conf');

    // If socket connection fails or no php-cgi instance is found
    if (empty($instancesPhpCgi)) {
        echo "php-cgi not running, for user: {$thisUser}. Killing lighttpd instances.\n";
        $socketError = true;
    } else {
        // Probe every expected php-cgi socket so partial worker crashes become
        // visible instead of leaving the user with intermittent 502 responses.
        foreach ($socketPaths as $socketPath) {
            $socket = fsockopen('unix://'.$socketPath, 0, $errno, $errstr, 5);
            if (!$socket or $errno or $errstr) {
                echo "Error when attempting to connect to socket {$socketPath}: {$errno}, {$errstr}\n";
                echo "php-cgi, for user: {$thisUser}. Killing lighttpd instances.\n";
                $socketError = true;
                break;
            }

            fclose($socket);
        }
    }
    if ($socketError) {
        echo "Killing (if any) lighttpd for user: {$thisUser}\n";
        shell_exec("killall -15 -u {$thisUser} lighttpd; killall -15 -u {$thisUser} php-cgi; sleep 5; killall -9 -u {$thisUser} lighttpd; killall -9 -u {$thisUser} php-cgi;");
        usleep(50000);   // brief pause before relaunch
        pmssUserLog($thisUser, 'lighttpd restart requested');
    }
    // Let's actually test we get 401 auth requested!
    /* temp disabled, so much log spam and did not achieve desired results.
    $curl = curl_init("http://127.0.0.1/user-{$thisUser}/");
    curl_setopt( $curl, CURLOPT_HEADER, true);
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true);
    $httpResponse = curl_exec( $curl );

   if (strpos($httpResponse, 'HTTP/1.1 401 Unauthorized') === false) $instancesLighttpd = '';
    */

    if ($socketError || empty($instancesLighttpd)) {    // No instances at all? Ok time to start Lighttpd!
        echo "Start lighttpd for user: {$thisUser}\n";
        passthru('/scripts/startLighttpd ' . $thisUser);
        pmssUserLog($thisUser, 'lighttpd start requested');
        continue;
    }

}
