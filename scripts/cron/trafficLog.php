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
require_once '/scripts/lib/user/log.php';

$logger = new Logger(__FILE__);

// Counters are listed then flushed below; an overlapping run would read the
// same counters twice and double-bill traffic. Hold the handle until exit —
// an unassigned handle is closed immediately, which releases the flock.
$pmssTrafficLogLock = pmssCronLockAcquire('trafficLog', [$logger, 'msg']);
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

// Rebuilding OUTPUT every run is also the counter reset: iptables-nft 1.8.7
// (Debian 11) returns 0 from -Z but leaves rule counters as they were.
$monitoringCommands = networkLoadMonitoringCommands(
    null,
    static function (string $message) use ($logger): void {
        $logger->msg(str_replace('setupNetwork:', 'trafficLog:', $message));
    }
);
// networkLoadMonitoringCommands() checks helper status before networkParseMonitoringCommands().
// One restore commit leaves no window in which OUTPUT is empty or half built,
// so no egress goes uncounted or unmarked for FireQOS during the rebuild.
if ($monitoringCommands !== [] && !networkReplaceOutputRulesAtomically($monitoringCommands)) {
    $logger->msg('trafficLog: iptables-restore failed, rebuilding OUTPUT one rule at a time');
    networkRunIptables('-F OUTPUT');
    foreach ($monitoringCommands as $monitoringCommand) {
        networkRunIptables($monitoringCommand);
    }
}

$parsedUsage = pmssTrafficParseOutputUsage($usage, $localnets);

$logger->msg("Collecting data");

foreach ($userUids as $thisUser => $thisUid) {
    $thisUserTraffic = $parsedUsage['traffic'][$thisUid] ?? 0;
    $thisUserTrafficLocal = $parsedUsage['local'][$thisUid] ?? 0;

    // Drop samples that exceed the five-minute link budget instead of writing bad counters.
    foreach ([
        ['traffic', $thisUserTraffic, 'traffic anomaly: usage exceeds 90%% link max (%d bytes)'],
        ['LOCAL traffic', $thisUserTrafficLocal, 'traffic anomaly: local usage exceeds 90%% link max (%d bytes)'],
    ] as [$label, $bytes, $message]) {
        if (pmssTrafficBudgetExceeded($logdir.'error.log', $thisUser, $label, $bytes, $linkSpeed, "DEBUG USAGE DATA:\n{$usage}", $message)) {
            continue 2;
        }
    }

    if ($thisUserTraffic > 0) pmssAppendRootTimestampedLogEntry($logdir . $thisUser, ": {$thisUserTraffic}\n");

    if ($thisUserTrafficLocal > 0)
        pmssAppendRootTimestampedLogEntry($logdir . $thisUser . '-localnet', ": {$thisUserTrafficLocal}\n");

}

// Let's take unmatched!
$trafficUnmatched = $parsedUsage['unmatched'];
if ($trafficUnmatched > 0) {
    pmssAppendRootTimestampedLogEntry($logdir . 'unmatched-traffic', ": {$trafficUnmatched}\n");
}
