#!/usr/bin/php
<?php
require_once '/scripts/lib/traffic.php';
$trafficStatistics = new trafficStatistics;
/* List per user traffic for programmatic fetch */

$users = trim( `/scripts/listUsers.php` );
$users = explode("\n", $users);
if (count($users) == 0) die();

$userTrafficData = array();

foreach($users AS $thisUser) {
    $userTrafficData[$thisUser] = array();
    
    if (!file_exists("/home/{$thisUser}/.trafficData"))
            $userTrafficData[$thisUser]['normal'] = 0;
        else $userTrafficData[$thisUser]['normal'] = round( unserialize( file_get_contents("/home/{$thisUser}/.trafficData") )['raw']['month'] );
    
    if (!file_exists("/home/{$thisUser}/.trafficDataLocal"))
            $userTrafficData[$thisUser]['local'] = 0;
        else $userTrafficData[$thisUser]['local'] = round( unserialize( file_get_contents("/home/{$thisUser}/.trafficDataLocal") )['raw']['month'] );
    
    
    //$data = unserialize( file_get_contents("/home/{$thisUser}/.trafficData") );
    
    //$userTrafficData[ $thisUser ] = round( $data['raw']['month'] );
    

}

echo serialize( $userTrafficData );
