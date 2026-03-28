#!/usr/bin/env php
<?php
/**
 * Cron task: traffic Log.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Cron job log file paths match the cron schedule in root.cron

require_once '/scripts/lib/logger.php';
require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/resources/log.php';
require_once '/scripts/lib/user/userFilesystem.php';
$logger = new Logger(__FILE__);
if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) {
    require_once $pmssUserLogPath;
}
$logdir = '/var/log/pmss/traffic/';
$users = userFilesystem::listManagedUsersWithAdditionalUsers(['www-data']);
if (count($users) == 0) exit;    // Nothing to collect

// Load optional localnet definitions for counting LAN traffic separately.
// Multiple networks may be listed one per line in the central localnet config.
$localnets = networkLoadLocalnets();
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
    $thisUid = pmssResourceLogLookupManagedUid($thisUser);
    if ($thisUid === null) continue;
    $thisUserTraffic = 0;
    $thisUserTrafficLocal = 0;

    $thisUserTraffic = (int) `grep "0.0.0.0/0            owner UID match {$thisUid}" {$thisUsageFile} | grep "ACCEPT" | tr -s [:blank:] | awk '{print $2}'`;
    if ($localnets) {
            foreach ($localnets AS $thisLocalNet)
                $thisUserTrafficLocal += (int) `grep "{$thisLocalNet}       owner UID match {$thisUid}" {$thisUsageFile} | grep "ACCEPT" | tr -s [:blank:] | awk '{print $2}'`;
        }

		// Do not log if usage was MORE than linkspeed for the past 5 minutes.
	    if ($linkSpeed !== null && $linkSpeed > 0) {
	        if ($thisUserTraffic > ($linkSpeed * 1000 * 1000 * 60 * 5)*0.9) {
	            pmssAppendRootTimestampedLogEntry($logdir . 'error.log', ": User {$thisUser} traffic exceeds 90% link max: {$thisUserTraffic}\nDEBUG USAGE DATA:\n{$usage}\n");
                if (function_exists('pmssUserLog')) {
                    pmssUserLog($thisUser, sprintf('traffic anomaly: usage exceeds 90%% link max (%d bytes)', $thisUserTraffic));
                }
	            continue;  
	        }
	        // Note: variable name typo caused undefined output; use the correct value
	        if ($thisUserTrafficLocal > ($linkSpeed * 1000 * 1000 * 60 * 5)*0.9) {
	            pmssAppendRootTimestampedLogEntry(
	                $logdir . 'error.log',
	                ": User {$thisUser} LOCAL traffic exceeds 90% link max: {$thisUserTrafficLocal}\nDEBUG USAGE DATA:\n{$usage}\n"
	            );
                if (function_exists('pmssUserLog')) {
                    pmssUserLog($thisUser, sprintf('traffic anomaly: local usage exceeds 90%% link max (%d bytes)', $thisUserTrafficLocal));
                }
	            continue;
	        }
	    }

    if ($thisUserTraffic > 0) pmssAppendRootTimestampedLogEntry($logdir . $thisUser, ": {$thisUserTraffic}\n");

    if ($thisUserTrafficLocal > 0)
        pmssAppendRootTimestampedLogEntry($logdir . $thisUser . '-localnet', ": {$thisUserTrafficLocal}\n");

    // API push removed; central collector now uses pull workflow.
}

// Let's take unmatched!
$trafficUnmatched = (int) `grep "Chain OUTPUT (" {$thisUsageFile} | tr -s [:blank:]| cut -d' ' -f7`;
if ($trafficUnmatched > 0) {
    pmssAppendRootTimestampedLogEntry($logdir . 'unmatched-traffic', ": {$trafficUnmatched}");
}

// Remove the temp file, not required anymore
unlink($thisUsageFile);
