#!/usr/bin/php
<?php
// Script to populate users DB if none exists so far :)
require_once '/scripts/lib/users.php';
$systemUsers = users::systemUsers();	// Gets list of actual users found from the system /home

echo "This will overwrite the current system user DB, are you sure you want to continue? (Y/N)";
$continue = trim(FGETS(STDIN));
if (strtoupper($continue) != "Y") die();

$users = array();
// Iterate through each and ask for what plan details OUGHT to be
foreach($systemUsers AS $thisUser) {
    $accepted = 0;
    
    while($accepted == 0) {
        echo "* User: {$thisUser}\n";
        echo "rTorrent RAM Limit for this user: ";
        $thisRam = (int) trim(FGETS(STDIN));
        
        echo "Quota Limit (Gb) for this user: ";
        $thisQuota = (int) trim(FGETS(STDIN));
        
        echo "Quota burst limit (Gb) for this user (empty/0 for default 25%): ";
        $thisQuotaBurst = (int) trim(FGETS(STDIN));
        
        echo "rTorrent port for this user (0=unknown): ";
        $thisPort = (int) trim(FGETS(STDIN));
        
        echo "User suspended (Y/N)?";
        $thisSuspended = strtoupper( trim(FGETS(STDIN)) );
        
        if ($thisSuspended == 'Y') $thisSuspended = true;
            else $thisSuspended = false;

        if (empty($thisPort)) $thisPort = 0;
        if (empty($thisQuotaBurst)) $thisQuotaBurst = round( $thisQuota * 1.25 );

        echo "User {$thisUser} limits:\n\t";
        echo "rTorrent RAM: {$thisRam}, Quota limit: {$thisQuota}, Quota Burst: {$thisQuotaBurst}, rTorrent port: {$thisPort}, Suspended: {$thisSuspended}\n";        
        
        $thisAcceptCheck = 0;
        while ($thisAcceptCheck == 0) {
            echo "Accept (Y/N)?";
            $thisAccept = strtoupper( trim(FGETS(STDIN)) );
            if ($thisAccept == 'Y') $accepted = 1;
            if ($thisAccept == 'Y' or
                $thisAccept == 'N') $thisAcceptCheck = 1;
        }
    }
    
    echo "Accepted.\n\n";
    $users[ $thisUser ] = array(
        'rtorrentRam' => $thisRam,
        'quota' => $thisQuota,
        'quotaBurst' => $thisQuotaBurst,
        'rtorrentPort' => $thisPort,
        'suspended' => $thisSuspended
    );
    
}

file_put_contents( '/etc/seedbox/runtime/users', serialize($users) );
