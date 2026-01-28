#!/usr/bin/env php
<?php
// Configure Ip tables rules for monitoring network traffic usage

require_once '/scripts/lib/network/iptables.php';

$users = array_filter(explode("\n", trim(`/scripts/listUsers.php`)), 'strlen');
if (!$users) exit(0);
$users[] = 'www-data';

$mark = 1;

// Owner match is required for per-user accounting; skip if unavailable.
if (!networkIptablesOwnerMatchAvailable()) exit(0);

$localnets = ['185.148.0.0/22']; // #TODO Refactor hardcoded value
// Multiple networks may be defined, one per line, to mark "local" traffic.
if (file_exists('/etc/seedbox/config/localnet')) {
    $cfg = trim(file_get_contents('/etc/seedbox/config/localnet'));
    if ($cfg !== '') {
        $localnets = preg_split('/\r?\n/', $cfg);
        $localnets = $localnets ? array_filter($localnets, 'strlen') : [];
    }
} else {
    file_put_contents('/etc/seedbox/config/localnet', "185.148.0.0/22\n"); // #TODO Refactor hardcoded value
}

$localnets = $localnets ?: [];
$lastLocalNet = $localnets ? end($localnets) : '';

foreach ($users as $thisUser) {
    $thisUid = trim( shell_exec("id -u {$thisUser}") );
    if ($thisUid === '') continue;	// User does not exist anymore

    foreach ($localnets as $thisLocalNet) {
        echo "/sbin/iptables -A OUTPUT -d {$thisLocalNet} -m owner --uid-owner {$thisUid} -j ACCEPT\n";
    }
    if ($lastLocalNet !== '') echo "/sbin/iptables -A OUTPUT ! -d {$lastLocalNet} -m owner --uid-owner {$thisUid} -j MARK --set-mark {$mark}\n";
    echo "/sbin/iptables -A OUTPUT -m owner --uid-owner {$thisUid} -j ACCEPT\n";
    ++$mark;
}

foreach ($localnets as $thisLocalNet) {
    echo "/sbin/iptables -A OUTPUT -d {$thisLocalNet} -j ACCEPT\n";
}
