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
    $userTrafficData[$thisUser]['normal'] = file_exists("/home/{$thisUser}/.trafficData")
        ? round(unserialize(file_get_contents("/home/{$thisUser}/.trafficData"))['raw']['month'])
        : 0;
    $userTrafficData[$thisUser]['local'] = file_exists("/home/{$thisUser}/.trafficDataLocal")
        ? round(unserialize(file_get_contents("/home/{$thisUser}/.trafficDataLocal"))['raw']['month'])
        : 0;
    
    
    //$data = unserialize( file_get_contents("/home/{$thisUser}/.trafficData") );
    
    //$userTrafficData[ $thisUser ] = round( $data['raw']['month'] );
    

}

echo serialize( $userTrafficData );
