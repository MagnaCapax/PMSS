#!/usr/bin/php
<?php
// Collect traffic usage statistics

//CNC server not in use anymore, new management uses pull type instead of push
//require_once '/scripts/lib/serverApi.php';
//$serverApi = new remoteServerApi();

$logdir = '/var/log/pmss/traffic/';
$users = trim( `/scripts/listUsers.php` );
$users = explode("\n", $users);
if (count($users) == 0) exit;    // Nothing to collect
$users[] = 'www-data';  // Add www-data instance, we want to see this account aswell

$localnets = false;
if (file_exists('/etc/seedbox/config/localnet')) {
    $localnets = trim( file_get_contents('/etc/seedbox/config/localnet') );
    $localnets = explode("\n", $localnets);
    
}
require_once '/scripts/lib/networkInfo.php';

    // Collect the current iptables stats and then reset the counters
$usage = `/sbin/iptables -nvx -L OUTPUT | grep -v " MARK "; /sbin/iptables -Z`; 
if (empty($usage)) die(date('Y-m-d H:i:s') . " **** FATAL: Empty output from iptables???\n");

$thisUsageFile = '/tmp/trusage-' . date('Y-m-d') . '-' . sha1( time() . rand(0,1500000) );  // If too predictable filename someone could in theory intercept ...
if (!file_put_contents($thisUsageFile, $usage)) die( date('Y-m-d H:i:s') . ": Could not write data usage file {$thisUsageFile} with {$usage}\n\n";
chmod($thisUsageFile, 0600);

//echo "Data: \n {$usage} \n";

foreach($users AS $thisUser) {
    $thisUid = trim( shell_exec("id -u {$thisUser}") );
    $thisUserTraffic = 0;
    $thisUserTrafficLocal = 0;

        // Get this specific users data consumption
    $thisUserTraffic = (int) `grep "0.0.0.0/0            owner UID match {$thisUid}" {$thisUsageFile} | tr -s [:blank:] | cut -d' ' -f3`;
    if ($localnets !== false &&
        count($localnets) > 0) {
            foreach ($localnets AS $thisLocalNet)
                $thisUserTrafficLocal += (int) `grep "{$thisLocalNet}       owner UID match {$thisUid}" {$thisUsageFile} | tr -s [:blank:] | cut -d' ' -f3`;
                echo "Loggin {$thisLocalNet} for {$thisUser}/{$thisUid} result {$thisUserTrafficLocal}\n";
        }

   $thisUserTraffic = (int) trim( $thisUserTraffic );
   $thisUserTrafficLocal = (int) trim( $thisUserTrafficLocal );


	// Do not log if usage was MORE than linkspeed for the past 5 minutes.
    if ($thisUserTraffic > ($linkSpeed * 1000 * 1000 * 60 * 5)) {
        file_put_contents($logdir . 'error.log', date('Y-m-d H:i:s') . ": Logging user {$thisUser} traffic gathered was: {$thisUserTraffic}\nDEBUG USAGE DATA:\n{$usage}\n", FILE_APPEND);
        continue;  
    }
    if ($thisUserTrafficLocal > ($linkSpeed * 1000 * 1000 * 60 * 5)) {
        file_put_contents($logdir . 'error.log', date('Y-m-d H:i:s') . ": Logging user {$thisUser} LOCAL traffic gathered was: {$thisUserLocalTraffic}\nDEBUG USAGE DATA:\n{$usage}\n", FILE_APPEND);
        continue;  
    }



        // Append this collection stats to the user's log file
    if ($thisUserTraffic > 0) file_put_contents($logdir . $thisUser, date('Y-m-d H:i:s') . ": {$thisUserTraffic}\n", FILE_APPEND);

        // Apped to -localnet usage if that is being employed
    if ($thisUserTrafficLocal > 0)
        file_put_contents($logdir . $thisUser . '-localnet', date('Y-m-d H:i:s') . ": {$thisUserTrafficLocal}\n", FILE_APPEND);

    // Perhaps we should  remove this as we've not been using this maybe close to a decade now? 
    //$serverApi->makeCall('userTraffic', array('username' => $thisUser, 'traffic' => trim($thisUserTraffic) ) );
}

// Let's take unmatched!
$trafficUnmatched = (int) `grep "Chain OUTPUT (" {$thisUsageFile} | tr -s [:blank:]| cut -d' ' -f7`;
if ($trafficUnmatched > 0) {
    file_put_contents($logdir . 'unmatched-traffic', date('Y-m-d H:i:s') . ": {$trafficUnmatched}", FILE_APPEND);
    
}

// Remove the temp file, not required anymore
// temp do not remove as we need to debug
//unlink($thisUsageFile);
