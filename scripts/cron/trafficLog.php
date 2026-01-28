#!/usr/bin/env php
<?php
// Cron job log file paths match the cron schedule in root.cron

require_once '/scripts/lib/logger.php';
$logger = new Logger(__FILE__);
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
/**
 * Collect traffic usage statistics
 *
 * @copyright (C) Magna Capax Finland Oy 2023
 * @author Aleksi
 */


$logdir = '/var/log/pmss/traffic/';
$users = trim( `/scripts/listUsers.php` );
$users = array_filter(explode("\n", $users));
if (count($users) == 0) exit;    // Nothing to collect
$users[] = 'www-data';  // Add www-data instance, we want to see this account aswell

// Load optional localnet definitions for counting LAN traffic separately.
// Multiple networks may be listed one per line. If the file is missing
// create one with the default Pulsed Media LAN range so admins know where
// to customise it.
$localnets = ['185.148.0.0/22']; // #TODO Refactor hardcoded value
if (file_exists('/etc/seedbox/config/localnet')) {
    $cfg = trim(file_get_contents('/etc/seedbox/config/localnet'));
    if ($cfg !== '') {
        $localnets = preg_split('/\r?\n/', $cfg);
    }
} else {
    file_put_contents('/etc/seedbox/config/localnet', "185.148.0.0/22\n"); // #TODO Refactor hardcoded value
	}
	// Provides $link and $linkSpeed variables used for threshold checks
	require_once '/scripts/lib/networkInfo.php';
	$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (float) $linkSpeed : null;

	    // Collect the current iptables stats and then reset the counters
	$usage = `/sbin/iptables -nvx -L OUTPUT | grep -v " MARK "; /sbin/iptables -Z OUTPUT`;
	if (empty($usage)) die(date('Y-m-d H:i:s') . " **** FATAL: Empty output from iptables???\n");

// Debian 11 iptables -Z output doesn't work anymore .... we might miss a tiny fraction this way, but atleast not exponential growth
$monitoringRules = shell_exec('/scripts/util/makeMonitoringRules.php');
if (!empty($monitoringRules)) {
    passthru('/sbin/iptables -F OUTPUT'); // let's first clear old rules
    passthru($monitoringRules);
}

$thisUsageFile = '/tmp/trusage-' . date('Y-m-d_Hi') . '-' . sha1( time() . rand(0,1500000) );  // If too predictable filename someone could in theory intercept ...
if (!file_put_contents($thisUsageFile, $usage)) die( date('Y-m-d H:i:s') . ": Could not write data usage file {$thisUsageFile} with {$usage}\n\n");
chmod($thisUsageFile, 0600);

//echo "Data: \n {$usage} \n";

$logger->msg("Collecting data");

foreach($users AS $thisUser) {
    $thisUid = trim( shell_exec("id -u {$thisUser}") );
    $thisUserTraffic = 0;
    $thisUserTrafficLocal = 0;

        // Get this specific users data consumption
    $thisUserTraffic = (int) `grep "0.0.0.0/0            owner UID match {$thisUid}" {$thisUsageFile} | grep "ACCEPT" | tr -s [:blank:] | awk '{print $2}'`;
    if ($localnets !== false &&
        count($localnets) > 0) {
            foreach ($localnets AS $thisLocalNet)
                $thisUserTrafficLocal += (int) `grep "{$thisLocalNet}       owner UID match {$thisUid}" {$thisUsageFile} | grep "ACCEPT" | tr -s [:blank:] | awk '{print $2}'`;
                //echo "Loggin {$thisLocalNet} for {$thisUser}/{$thisUid} result {$thisUserTrafficLocal}\n";
        }

    $thisUserTraffic = (int) trim( $thisUserTraffic );
    $thisUserTrafficLocal = (int) trim( $thisUserTrafficLocal );


		// Do not log if usage was MORE than linkspeed for the past 5 minutes.
	    if ($linkSpeed !== null && $linkSpeed > 0) {
	        if ($thisUserTraffic > ($linkSpeed * 1000 * 1000 * 60 * 5)*0.9) {
	            file_put_contents($logdir . 'error.log', date('Y-m-d H:i:s') . ": User {$thisUser} traffic exceeds 90% link max: {$thisUserTraffic}\nDEBUG USAGE DATA:\n{$usage}\n", FILE_APPEND);
                if (function_exists('pmssUserLog')) {
                    pmssUserLog($thisUser, sprintf('traffic anomaly: usage exceeds 90%% link max (%d bytes)', $thisUserTraffic));
                }
	            continue;  
	        }
	        // Note: variable name typo caused undefined output; use the correct value
	        if ($thisUserTrafficLocal > ($linkSpeed * 1000 * 1000 * 60 * 5)*0.9) {
	            file_put_contents(
	                $logdir . 'error.log',
	                date('Y-m-d H:i:s') . ": User {$thisUser} LOCAL traffic exceeds 90% link max: {$thisUserTrafficLocal}\nDEBUG USAGE DATA:\n{$usage}\n",
	                FILE_APPEND
	            );
                if (function_exists('pmssUserLog')) {
                    pmssUserLog($thisUser, sprintf('traffic anomaly: local usage exceeds 90%% link max (%d bytes)', $thisUserTrafficLocal));
                }
	            continue;
	        }
	    }



        // Append this collection stats to the user's log file
    if ($thisUserTraffic > 0) file_put_contents($logdir . $thisUser, date('Y-m-d H:i:s') . ": {$thisUserTraffic}\n", FILE_APPEND);

        // Apped to -localnet usage if that is being employed
    if ($thisUserTrafficLocal > 0)
        file_put_contents($logdir . $thisUser . '-localnet', date('Y-m-d H:i:s') . ": {$thisUserTrafficLocal}\n", FILE_APPEND);

    // API push removed; central collector now uses pull workflow.
}

// Let's take unmatched!
$trafficUnmatched = (int) `grep "Chain OUTPUT (" {$thisUsageFile} | tr -s [:blank:]| cut -d' ' -f7`;
if ($trafficUnmatched > 0) {
    file_put_contents($logdir . 'unmatched-traffic', date('Y-m-d H:i:s') . ": {$trafficUnmatched}", FILE_APPEND);
    
}

// Remove the temp file, not required anymore
unlink($thisUsageFile);
