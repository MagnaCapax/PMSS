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

    $lineParts = [
        date('Y-m-d H:i:s'),
        (string) $delta['io_read'],
        (string) $delta['io_write'],
        (string) $delta['io_read_ops'],
        (string) $delta['io_write_ops'],
        (string) $delta['cpu_nsec'],
        (string) $state['memory'],
        (string) $state['tasks'],
    ];
    if (isset($state['memory_anon']) && isset($state['memory_file'])) {
        $lineParts[] = (string) $state['memory_anon'];
        $lineParts[] = (string) $state['memory_file'];
    }
    $line = implode(' ', $lineParts);
    @file_put_contents($logDir.'/'.$user, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
}
