#!/usr/bin/env php
<?php
/**
 * Cron task: per-user resource log (systemd slice accounting).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/user/userFilesystem.php';
require_once '/scripts/lib/resources/log.php';
$logDir = '/var/log/pmss/resources';
$stateDir = '/var/run/pmss/resources';

if (!pmssEnsureSafeDir($logDir, 0755) || !pmssEnsureSafeDir($stateDir, 0700)) {
    fwrite(STDERR, "Failed to prepare resource log directories.\n");
    exit(1);
}

$users = userFilesystem::listManagedUsersWithAdditionalUsers(['www-data']);
if ($users === []) {
    exit(0);
}
foreach ($users as $user) {
    if (($uid = pmssResourceLogLookupManagedUid($user)) === null || ($counters = pmssResourceLogReadCounters($uid)) === null) {
        continue;
    }

    ['delta' => $delta, 'state' => $state] = pmssResourceLogUpdateState($stateDir.'/'.$user.'.json', $counters);

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
    isset($state['memory_anon'], $state['memory_file'])
        && array_push($lineParts, (string) $state['memory_anon'], (string) $state['memory_file']);
    if (!pmssAppendUserFile($logDir.'/'.$user, implode(' ', $lineParts).PHP_EOL, 'root', 0644)) {
        fwrite(STDERR, "Failed to append resource log for {$user}.\n");
    }
}
