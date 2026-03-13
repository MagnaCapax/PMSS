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
(new ResourceStorage())->ensureRuntime();

if (($user = $processor->detectWorkerUser($argv)) !== null) {
    if (!$processor->validateUser($user)) {
        echo "Invalid user specified: {$user}\n";
        exit(0);
    }

    $processor->processUser($user, $processor->buildCompareTimes());
    exit(0);
}

// Prevent overlapping cron runs by holding a process-wide lock.
$lockFile = '/var/run/pmss/resourceStats.lock';
$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle !== false) {
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string) getmypid());
} else {
    logMessage(date('c').": Unable to open lock file {$lockFile} for resourceStats");
}

$users = $processor->discoverUsers();
if (empty($users)) {
    die("No users in this system!\n");
}

$processor->spawnWorkers($_SERVER['argv'][0], $users);
