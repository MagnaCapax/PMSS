#!/usr/bin/env php
<?php
/**
 * Gather per-user resource usage and calculate statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/resources/processor.php';

$processor = new ResourceStatsProcessor(new resourceStatistics());
$processor->ensureRuntime();

if (($user = $processor->detectWorkerUser($argv)) !== null) {
    if (!$processor->validateUser($user)) {
        echo "Invalid user specified: {$user}\n";
    } else {
        $processor->processUser($user, $processor->buildCompareTimes());
    }
    exit(0);
}

$lockFile = '/var/run/pmss/resourceStats.lock';
$lockBusy = false;
$lockHandle = pmssLockFileAcquire($lockFile, true, 'c+', false, true, $lockBusy);
if ($lockHandle !== false) {
    if ($lockBusy) {
        exit(0);
    }
    pmssLockHandleWritePid($lockHandle);
} else {
    logMessage(date('c').": Unable to open lock file {$lockFile} for resourceStats");
}

$users = $processor->discoverUsers();
if (empty($users)) {
    die("No users in this system!\n");
}

$processor->spawnWorkers($_SERVER['argv'][0], $users);
