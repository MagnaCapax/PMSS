#!/usr/bin/php
<?php
/*
Pulsed Media Seedbox Management Software "PMSS"
This script manages and monitors user-specific lighttpd and php-cgi processes.
*/

echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    #TODO Uh Oh next one should be separate script :) This is separate task altogether. Works here too as expected, just a bit confusing
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            continue;  //Suspended
    }
    
    $instancesLighttpd = shell_exec("pgrep -u {$thisUser} lighttpd");
    $instancesPhpCgi = shell_exec("pgrep -u {$thisUser} php-cgi");
    $socketError = false;

    // If socket connection fails or no php-cgi instance is found
    if (empty($instancesPhpCgi)) {        
            echo "php-cgi not running, for user: {$thisUser}. Killing lighttpd instances.\n";
            restartLighttpd($thisUser);
            continue;

    }
                              
    // Connect to php-cgi socket
    /*for ($i = 0; $i < 4; $i++) {  // Adjust the number 4 according to your `max-procs` value
        $socket = fsockopen("unix:///home/{$thisUser}/.lighttpd/php.socket-$i", 0, $errno, $errstr, 1);
        if (!$socket or $errno or $errstr) {        
            echo "Error when attempting to connect to socket /home/{$thisUser}/.lighttpd/php.socket-$i: {$errno}, {$errstr}\n";
            echo "php-cgi, for user: {$thisUser}. Killing lighttpd instances.\n";            
            $socketError = true;
            break;
        }

        if ($socketError); continue;
    }
    if ($socketError); { restartLighttpd($thisUser); continue; }*/



    // Let's actually test we get 401 auth requested!
    /* temp disabled, so much log spam and did not achieve desired results.
    $curl = curl_init("http://127.0.0.1/user-{$thisUser}/");
    curl_setopt( $curl, CURLOPT_HEADER, true);
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true);
    $httpResponse = curl_exec( $curl );

   if (strpos($httpResponse, 'HTTP/1.1 401 Unauthorized') === false) $instancesLighttpd = '';
    */
    
    if(empty($instancesLighttpd)) {    // No instances at all? Ok time to start Lighttpd!
        startLighttpd($thisUser);
        continue;
    }
    
}


function startLighttpd($thisUser) {    // this actually calls the function to start Lighttpd :)
    echo "Start lighttpd for user: {$thisUser}\n";
    passthru('/scripts/startLighttpd ' . $thisUser);
}

function restartLighttpd($thisUser) {    // Kill any php-cgi or lighttpd process for user, make sure no double launch
    echo "Killing (if any) lighttpd for user: {$thisUser}\n";
    shell_exec("killall -15 -u {$thisUser} lighttpd; killall -15 -u {$thisUser} php-cgi; sleep 5; killall -9 -u {$thisUser} lighttpd; killall -9 -u {$thisUser} php-cgi; sleep 0.05;");
    startLighttpd($thisUser);
}
