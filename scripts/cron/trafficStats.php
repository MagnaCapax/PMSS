#!/usr/bin/env php
<?php
/**
 * Gather per user traffic usage and calculate statistics
 *
 * @copyright (C) Magna Capax Finland Oy 2023
 * @author Aleksi
 */

require_once '/scripts/lib/traffic.php';
require_once '/scripts/lib/traffic/processor.php';

$processor = new TrafficStatsProcessor(new trafficStatistics());
$processor->ensureRuntime();
$compareTimes = $processor->buildCompareTimes();

if (($user = $processor->detectWorkerUser($argv)) !== null) {
    if ($processor->validateUser($user)) {
        $processor->processUser($user, $compareTimes);
    } else {
        echo "Invalid user specified: {$user}\n";
    }
    exit(0);
}

$users = $processor->discoverUsers();
if (empty($users)) {
    die("No users in this system!\n");
}

$processor->spawnWorkers($_SERVER['argv'][0], $users);
