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
require_once '/scripts/lib/network/iptables.php';
require_once '/scripts/lib/resources/log.php';
require_once '/scripts/lib/traffic.php';

$logger = new Logger(__FILE__);
if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) {
    require_once $pmssUserLogPath;
}
$logdir = '/var/log/pmss/traffic/';
$userUids = pmssResourceLogManagedUserUids();
if (count($userUids) == 0) exit;    // Nothing to collect

// Load optional localnet definitions for counting LAN traffic separately.
// Multiple networks may be listed one per line in the central localnet config.
$localnets = networkLoadLocalnets();
	// Provides $link and $linkSpeed variables used for threshold checks
	require_once '/scripts/lib/networkInfo.php';
	$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (float) $linkSpeed : null;

	    // Collect the current iptables stats and then reset the counters.
	$usage = pmssTrafficCollectOutputUsage(null, [$logger, 'msg']);
	if ($usage === null || trim($usage) === '') die(date('Y-m-d H:i:s') . " **** FATAL: Empty output from iptables???\n");

// Debian 11 iptables -Z output doesn't work anymore .... we might miss a tiny fraction this way, but atleast not exponential growth
$monitoringRules = shell_exec('/scripts/util/makeMonitoringRules.php');
$monitoringCommands = networkParseMonitoringCommands(is_string($monitoringRules) ? $monitoringRules : '');
if ($monitoringCommands !== []) {
    networkRunIptables('-F OUTPUT'); // let's first clear old rules
    foreach ($monitoringCommands as $monitoringCommand) {
        networkRunIptables($monitoringCommand);
    }
}

$parsedUsage = pmssTrafficParseOutputUsage($usage, $localnets);

$logger->msg("Collecting data");

foreach($userUids AS $thisUser => $thisUid) {
    $thisUserTraffic = $parsedUsage['traffic'][$thisUid] ?? 0;
    $thisUserTrafficLocal = $parsedUsage['local'][$thisUid] ?? 0;

		// Do not log if usage was MORE than linkspeed for the past 5 minutes.
	    if (pmssTrafficBudgetExceeded($logdir . 'error.log', $thisUser, 'traffic', $thisUserTraffic, $linkSpeed, "DEBUG USAGE DATA:\n{$usage}", 'traffic anomaly: usage exceeds 90%% link max (%d bytes)')) {
	            continue;  
	    }
	    // Note: variable name typo caused undefined output; use the correct value
	    if (pmssTrafficBudgetExceeded($logdir . 'error.log', $thisUser, 'LOCAL traffic', $thisUserTrafficLocal, $linkSpeed, "DEBUG USAGE DATA:\n{$usage}", 'traffic anomaly: local usage exceeds 90%% link max (%d bytes)')) {
	            continue;
	    }

    if ($thisUserTraffic > 0) pmssAppendRootTimestampedLogEntry($logdir . $thisUser, ": {$thisUserTraffic}\n");

    if ($thisUserTrafficLocal > 0)
        pmssAppendRootTimestampedLogEntry($logdir . $thisUser . '-localnet', ": {$thisUserTrafficLocal}\n");

}

// Let's take unmatched!
$trafficUnmatched = $parsedUsage['unmatched'];
if ($trafficUnmatched > 0) {
    pmssAppendRootTimestampedLogEntry($logdir . 'unmatched-traffic', ": {$trafficUnmatched}");
}
