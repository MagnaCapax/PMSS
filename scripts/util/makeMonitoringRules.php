#!/usr/bin/php
<?php
// Configure Ip tables rules for monitoring network traffic usage

$users = trim( `/scripts/listUsers.php` );
if (empty($users)) exit;

$users = explode("\n", $users);
if (count($users) == 0) die("No users setup - nothing to do\n");
$users[] = 'www-data';

$mark = 1;

$localnets = false;
if (file_exists('/etc/seedbox/config/localnet')) {
    $localnets = trim( file_get_contents('/etc/seedbox/config/localnet') );
    $localnets = explode("\n", $localnets);
    
}



foreach($users AS $thisUser) {
    $thisUid = trim( shell_exec("id -u {$thisUser}") );
    if (empty($thisUid)) continue;	// User does not exist anymore

    if ($localnets !== false &&
        count($localnets) > 0) {
        
        foreach($localnets AS $thisLocalNet) {
            echo "/sbin/iptables -A OUTPUT -d {$thisLocalNet} -m owner --uid-owner {$thisUid} -j ACCEPT\n";
	}
    }
    echo "/sbin/iptables -A OUTPUT ! -d {$thisLocalNet} -m owner --uid-owner {$thisUid} -j MARK --set-mark {$mark}\n";
    echo "/sbin/iptables -A OUTPUT -m owner --uid-owner {$thisUid} -j ACCEPT\n";
    ++$mark;


}

if ($localnets !== false &&
    count($localnets) > 0)
    foreach($localnets AS $thisLocalNet) {
        echo "/sbin/iptables -A OUTPUT -d {$thisLocalNet} -j ACCEPT\n";

    }


