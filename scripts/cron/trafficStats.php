#!/usr/bin/env php
<?php
/**
 * Gather per user traffic usage and calculate statistics
 *
 * @copyright (C) Magna Capax Finland Oy 2023
 * @author Aleksi
 *
 * @license GPL-3.0-only
 */

require_once '/scripts/lib/traffic/processor.php';

// Keep the legacy runtime-preparation side effect before worker dispatch.
(new TrafficStorage())->ensureRuntime();
$processor = new TrafficStatsProcessor(new trafficStatistics());

if (($user = $processor->detectWorkerUser($argv)) !== null) {
    if (!$processor->validateUser($user)) {
        echo "Invalid user specified: {$user}\n";
        exit(0);
    }

    $processor->processUser($user, $processor->buildCompareTimes());
    exit(0);
}

$users = $processor->discoverUsers();
if (empty($users)) {
    die("No users in this system!\n");
}

$processor->spawnWorkers($_SERVER['argv'][0], $users);
