#!/usr/bin/env php
<?php
/**
 * Cron task: per-user ingress traffic log (systemd IPAccounting).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/traffic.php';
require_once '/scripts/lib/traffic/ingress.php';
require_once '/scripts/lib/networkInfo.php';

$logDir = '/var/log/pmss/traffic-ingress';
$stateDir = '/var/run/pmss/trafficIngress';
$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (float) $linkSpeed : null;

if (!pmssEnsureSafeDir($logDir, 0755) || !pmssEnsureSafeDir($stateDir, 0700)) {
    fwrite(STDERR, "Failed to prepare ingress traffic directories.\n");
    exit(1);
}

$userUids = pmssResourceLogManagedUserUids();
if (empty($userUids)) {
    exit(0);
}
foreach ($userUids as $user => $uid) {
    $counters = pmssTrafficIngressReadCounters($uid);
    if ($counters === null) {
        continue;
    }

    $statePath = $stateDir.'/'.$user.'.json';
    ['delta' => $delta, 'previous_ingress' => $previousIngress] = pmssTrafficIngressUpdateState($statePath, $counters);

    if ($delta > 0) {
        $previousDisplay = $previousIngress !== null ? $previousIngress : 'n/a';
        if (pmssTrafficBudgetExceeded($logDir.'/error.log', $user, 'ingress', $delta, $linkSpeed, "DEBUG COUNTERS: ingress={$counters['ingress']} previous={$previousDisplay}", 'ingress anomaly: usage exceeds 90%% link max (%d bytes)')) {
            continue;
        }

        pmssAppendRootTimestampedLogEntry($logDir.'/'.$user, ": {$delta}\n");
    }
}
