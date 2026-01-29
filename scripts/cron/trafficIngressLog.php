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

$logDir = '/var/log/pmss/traffic-ingress';
$stateDir = '/var/run/pmss/trafficIngress';

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
        @file_put_contents($logDir.'/'.$user, date('Y-m-d H:i:s').": {$delta}\n", FILE_APPEND);
    }
}
