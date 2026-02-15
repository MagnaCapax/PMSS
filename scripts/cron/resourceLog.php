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
        $decoded = json_decode((string) @file_get_contents($statePath), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $current = [];
    $delta = [];
    foreach (['io_read', 'io_write', 'cpu_nsec'] as $field) {
        $currentValue = (int) $counters[$field];
        $previousValue = isset($state[$field]) ? (int) $state[$field] : null;
        $current[$field] = $currentValue;
        $delta[$field] = ($previousValue !== null && $currentValue >= $previousValue) ? $currentValue - $previousValue : $currentValue;
    }

    $state = $current + [
        'memory' => (int) $counters['memory'],
        'tasks' => (int) $counters['tasks'],
        'ts' => time(),
    ];
    $payload = json_encode($state);
    if (is_string($payload)) {
        @file_put_contents($statePath, $payload);
        @chmod($statePath, 0600);
    }

    $line = sprintf(
        '%s %d %d %d %d %d',
        date('Y-m-d H:i:s'),
        $delta['io_read'],
        $delta['io_write'],
        $delta['cpu_nsec'],
        $state['memory'],
        $state['tasks']
    );
    @file_put_contents($logDir.'/'.$user, $line.PHP_EOL, FILE_APPEND);
}
