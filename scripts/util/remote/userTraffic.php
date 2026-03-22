#!/usr/bin/env php
<?php
/**
 * Utility script: user Traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/traffic.php';
require_once '/scripts/lib/user/traffic.php';
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
