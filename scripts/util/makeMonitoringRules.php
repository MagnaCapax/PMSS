#!/usr/bin/env php
<?php
/**
 * Utility script: make Monitoring Rules.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Configure Ip tables rules for monitoring network traffic usage

require_once '/scripts/lib/network/iptables.php';
require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/resources/log.php';
require_once '/scripts/lib/user/userFilesystem.php';

$users = userFilesystem::listManagedUsersWithAdditionalUsers(['www-data']);
if (!$users) exit(0);

$mark = 1;

// Owner match is required for per-user accounting; skip if unavailable.
if (!networkIptablesOwnerMatchAvailable()) exit(0);

// Multiple networks may be defined, one per line, to mark "local" traffic.
$localnets = networkLoadLocalnets();
$lastLocalNet = $localnets ? end($localnets) : '';

foreach ($users as $thisUser) {
    $thisUid = pmssResourceLogLookupManagedUid($thisUser);
    if ($thisUid === null) continue;	// User does not exist anymore

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
