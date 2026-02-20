#!/usr/bin/env php
<?php
/**
 * Cron task: per-user ingress traffic log (systemd IPAccounting).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/userLifecycle.php';
require_once '/scripts/lib/traffic/ingress.php';
require_once '/scripts/lib/networkInfo.php';

$logDir = '/var/log/pmss/traffic-ingress';
$stateDir = '/var/run/pmss/trafficIngress';
$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (float) $linkSpeed : null;

if (!pmssTrafficIngressEnsureDir($logDir, 0755) || !pmssTrafficIngressEnsureDir($stateDir, 0700)) {
    fwrite(STDERR, "Failed to prepare ingress traffic directories.\n");
    exit(1);
}

$usersRaw = @shell_exec('/scripts/listUsers.php');
$usersRaw = is_string($usersRaw) ? trim($usersRaw) : '';
$users = array_filter(explode("\n", $usersRaw));
if (empty($users)) {
    exit(0);
}
$users[] = 'www-data';

foreach ($users as $user) {
    $user = trim($user);
    if ($user === '') {
        continue;
    }
    $normalized = function_exists('pmssNormalizeUsername')
        ? pmssNormalizeUsername($user)
        : strtolower($user);
    if ($normalized !== $user) {
        continue;
    }
    if (!preg_match('/^[a-z0-9-]+$/', $user)) {
        continue;
    }
    if ($user !== 'www-data' && function_exists('pmssValidateUsername') && !pmssValidateUsername($user)) {
        continue;
    }

    $uid = pmssTrafficIngressLookupUid($user);
    if ($uid === null) {
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
    $delta = $currentIngress;
    if ($previousIngress !== null && $currentIngress >= $previousIngress) {
        $delta = $currentIngress - $previousIngress;
    }

    $state = [
        'ingress' => $currentIngress,
        'egress'  => (int) $counters['egress'],
        'ts'      => time(),
    ];
    pmssTrafficIngressWriteState($statePath, $state);

    if ($delta > 0) {
        if ($linkSpeed !== null && $linkSpeed > 0) {
            $maxDelta = ($linkSpeed * 1000 * 1000 * 60 * 5) * 0.9;
            if ($delta > $maxDelta) {
                $previousDisplay = $previousIngress !== null ? $previousIngress : 'n/a';
                $message = date('Y-m-d H:i:s')
                    .": User {$user} ingress exceeds 90% link max: {$delta}\n"
                    ."DEBUG COUNTERS: ingress={$currentIngress} previous={$previousDisplay}\n";
                @file_put_contents($logDir.'/error.log', $message, FILE_APPEND);
                if (function_exists('pmssUserLog')) {
                    pmssUserLog($user, sprintf('ingress anomaly: usage exceeds 90%% link max (%d bytes)', $delta));
                }
                continue;
            }
        }

        @file_put_contents($logDir.'/'.$user, date('Y-m-d H:i:s').": {$delta}\n", FILE_APPEND);
    }
}
