#!/usr/bin/env php
<?php
/**
 * Cron task: per-user resource log (systemd slice accounting).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/resources/log.php';
$logDir = '/var/log/pmss/resources';
$stateDir = '/var/run/pmss/resources';

if (!pmssEnsureSafeDir($logDir, 0755) || !pmssEnsureSafeDir($stateDir, 0700)) {
    fwrite(STDERR, "Failed to prepare resource log directories.\n");
    exit(1);
}

$userUids = pmssResourceLogManagedUserUids();
if ($userUids === []) {
    exit(0);
}
foreach ($userUids as $user => $uid) {
    if (($counters = pmssResourceLogReadCounters($uid)) === null) {
        continue;
    }

    ['delta' => $delta, 'state' => $state] = pmssResourceLogUpdateState($stateDir.'/'.$user.'.json', $counters);

    if (!pmssAppendRootTimestampedLogEntry($logDir.'/'.$user, ' '.implode(' ', pmssResourceLogLineParts($delta, $state)).PHP_EOL)) {
        fwrite(STDERR, "Failed to append resource log for {$user}.\n");
    }
}
