#!/usr/bin/env php
<?php
/**
 * Cron task: per-user ingress traffic log (systemd IPAccounting).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/user/userFilesystem.php';
require_once '/scripts/lib/traffic/ingress.php';
require_once '/scripts/lib/networkInfo.php';

$logDir = '/var/log/pmss/traffic-ingress';
$stateDir = '/var/run/pmss/trafficIngress';
$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (float) $linkSpeed : null;

if (!pmssEnsureSafeDir($logDir, 0755) || !pmssEnsureSafeDir($stateDir, 0700)) {
    fwrite(STDERR, "Failed to prepare ingress traffic directories.\n");
    exit(1);
}

$users = userFilesystem::listManagedUsersWithAdditionalUsers(['www-data']);
if (empty($users)) {
    exit(0);
}
foreach ($users as $user) {
    if (($uid = pmssResourceLogLookupManagedUid($user)) === null) {
        continue;
    }

    $counters = pmssTrafficIngressReadCounters($uid);
    if ($counters === null) {
        continue;
    }

    $statePath = $stateDir.'/'.$user.'.json';
    $state = pmssTrafficIngressReadState($statePath);

    $currentIngress = (int) $counters['ingress'];
    $previousIngress = isset($state['ingress']) ? (int) $state['ingress'] : null;
    $delta = ($previousIngress !== null && $currentIngress >= $previousIngress)
        ? $currentIngress - $previousIngress
        : $currentIngress;

    $state = [
        'ingress' => $currentIngress,
        'egress'  => (int) $counters['egress'],
        'ts'      => time(),
    ];
    pmssTrafficIngressWriteState($statePath, $state);

    if ($delta > 0) {
        if ($linkSpeed > 0) {
            $maxDelta = ($linkSpeed * 1000 * 1000 * 60 * 5) * 0.9;
            if ($delta > $maxDelta) {
                $previousDisplay = $previousIngress !== null ? $previousIngress : 'n/a';
                pmssAppendRootTimestampedLogEntry(
                    $logDir.'/error.log',
                    ": User {$user} ingress exceeds 90% link max: {$delta}\nDEBUG COUNTERS: ingress={$currentIngress} previous={$previousDisplay}\n"
                );
                if (function_exists('pmssUserLog')) {
                    pmssUserLog($user, sprintf('ingress anomaly: usage exceeds 90%% link max (%d bytes)', $delta));
                }
                continue;
            }
        }

        pmssAppendRootTimestampedLogEntry($logDir.'/'.$user, ": {$delta}\n");
    }
}
