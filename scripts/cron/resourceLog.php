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

$users = array_filter(array_map('trim', explode("\n", (string) @shell_exec('/scripts/listUsers.php'))), 'strlen');
if ($users === []) {
    exit(0);
}
$users[] = 'www-data';

foreach ($users as $user) {
    if (!pmssResourceLogIsValidUser($user)
        || ($uid = pmssResourceLogLookupUid($user)) === null
        || ($counters = pmssResourceLogReadCounters($uid)) === null) {
        continue;
    }

    $statePath = $stateDir.'/'.$user.'.json';
    ['delta' => $delta, 'state' => $state] = pmssResourceLogUpdateState($statePath, $counters);

    $line = sprintf(
        '%s %d %d %d %d %d %d %d',
        date('Y-m-d H:i:s'),
        $delta['io_read'],
        $delta['io_write'],
        $delta['io_read_ops'],
        $delta['io_write_ops'],
        $delta['cpu_nsec'],
        $state['memory'],
        $state['tasks']
    );
    @file_put_contents($logDir.'/'.$user, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
}
