#!/usr/bin/env php
<?php
/**
 * Gather per-user ingress traffic usage and calculate statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/traffic.php';
require_once '/scripts/lib/traffic/processor.php';

$paths = [
    'traffic_dir'  => '/var/log/pmss/traffic-ingress',
    'runtime_dir'  => '/var/run/pmss/trafficIngress',
    'traffic_mode' => 'ingress',
];

$stats = new trafficStatistics($paths);
$processor = new TrafficStatsProcessor($stats, $paths);
$processor->ensureRuntime();

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
