#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            continue;  //Suspended
    }
    
    $instances = shell_exec('pgrep -u' . $thisUser . ' lighttpd');
    //echo "Instances:\n{$instances}\n";
    $instancesPhp = shell_exec("pgrep -u{$thisUser} php-cgi");
    if (empty($instancesPhp) &&
        !empty($instances) ) shell_exec("killall -9 -u{$thisUser} lighttpd");

// Let's actually test we get 401 auth requested!

    $curl = curl_init("http://127.0.0.1/user-{$thisUser}/");
    curl_setopt( $curl, CURLOPT_HEADER, true);
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true);
    $httpResponse = curl_exec( $curl );

   if (strpos($httpResponse, 'HTTP/1.1 401 Unauthorized') === false) $instances = '';


    if (empty($instancesPhp)) $instances = '';
    
    if(empty($instances)) {    // No instances at all? Ok time to start rTorrent!
        start($thisUser);
        continue;
    }
    
}


function start($user) {    // this actually calls the function to start rTorrent :)
    echo "Start lighttpd for user: {$user}\n";
    passthru('/scripts/startLighttpd ' . $user);
}
