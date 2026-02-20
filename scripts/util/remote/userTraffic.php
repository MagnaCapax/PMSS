#!/usr/bin/env php
<?php
/**
 * Utility script: user Traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/traffic.php';
/* List per user traffic for programmatic fetch */

$users = trim( `/scripts/listUsers.php` );
$users = explode("\n", $users);
if (count($users) == 0) die();

foreach($users AS $thisUser) {
    $userTrafficData[$thisUser]['normal'] = pmssReadUserTrafficMonth("/home/{$thisUser}/.trafficData");
    $userTrafficData[$thisUser]['local'] = pmssReadUserTrafficMonth("/home/{$thisUser}/.trafficDataLocal");
    $userTrafficData[$thisUser]['ingress'] = pmssReadUserTrafficMonth("/home/{$thisUser}/.trafficDataIngress");
}

echo serialize( $userTrafficData );

/**
 * Read the monthly traffic value from a serialized traffic data file.
 */
function pmssReadUserTrafficMonth(string $path): int
{
    if (!is_file($path) || is_link($path)) {
        return 0;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return 0;
    }

    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data) || !isset($data['raw']['month']) || !is_numeric($data['raw']['month'])) {
        return 0;
    }

    return (int) round($data['raw']['month']);
}
