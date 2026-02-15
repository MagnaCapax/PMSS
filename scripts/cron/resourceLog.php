#!/usr/bin/env php
<?php
/**
 * Cron task: per-user resource log (systemd slice accounting).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/userLifecycle.php';
require_once '/scripts/lib/resources/log.php';
$logDir = '/var/log/pmss/resources';
$stateDir = '/var/run/pmss/resources';

if (!pmssResourceLogEnsureDir($logDir, 0755) || !pmssResourceLogEnsureDir($stateDir, 0700)) {
    fwrite(STDERR, "Failed to prepare resource log directories.\n");
    exit(1);
}

$users = pmssResourceLogLoadUsers();
if (empty($users)) {
    exit(0);
}

foreach ($users as $user) {
    $user = trim($user);
    if (!pmssResourceLogIsValidUser($user)) {
        continue;
    }

    $uid = pmssResourceLogLookupUid($user);
    if ($uid === null) {
        continue;
    }

    $counters = pmssResourceLogReadCounters($uid);
    if ($counters === null) {
        continue;
    }

    $statePath = $stateDir.'/'.$user.'.json';
    $result = pmssResourceLogUpdateState($statePath, $counters);
    $delta = $result['delta'];
    $state = $result['state'];

    $line = sprintf(
        '%s %d %d %d %d %d',
        date('Y-m-d H:i:s'),
        $delta['io_read'],
        $delta['io_write'],
        $delta['cpu_nsec'],
        $state['memory'],
        $state['tasks']
    );
    @file_put_contents($logDir.'/'.$user, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
}
