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
    $state = [];
    if (is_file($statePath)) {
        $rawState = @file_get_contents($statePath);
        $decoded = is_string($rawState) ? json_decode($rawState, true) : null;
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $currentRead = (int) $counters['io_read'];
    $currentWrite = (int) $counters['io_write'];
    $currentCpu = (int) $counters['cpu_nsec'];

    $previousRead = isset($state['io_read']) ? (int) $state['io_read'] : null;
    $previousWrite = isset($state['io_write']) ? (int) $state['io_write'] : null;
    $previousCpu = isset($state['cpu_nsec']) ? (int) $state['cpu_nsec'] : null;

    $deltaRead = ($previousRead !== null && $currentRead >= $previousRead) ? $currentRead - $previousRead : $currentRead;
    $deltaWrite = ($previousWrite !== null && $currentWrite >= $previousWrite) ? $currentWrite - $previousWrite : $currentWrite;
    $deltaCpu = ($previousCpu !== null && $currentCpu >= $previousCpu) ? $currentCpu - $previousCpu : $currentCpu;

    $state = [
        'io_read'  => $currentRead,
        'io_write' => $currentWrite,
        'cpu_nsec' => $currentCpu,
        'memory'   => (int) $counters['memory'],
        'tasks'    => (int) $counters['tasks'],
        'ts'       => time(),
    ];
    $payload = json_encode($state);
    if (is_string($payload)) {
        @file_put_contents($statePath, $payload);
        @chmod($statePath, 0600);
    }

    $line = sprintf(
        '%s %d %d %d %d %d',
        date('Y-m-d H:i:s'),
        $deltaRead,
        $deltaWrite,
        $deltaCpu,
        $state['memory'],
        $state['tasks']
    );
    @file_put_contents($logDir.'/'.$user, $line.PHP_EOL, FILE_APPEND);
}
