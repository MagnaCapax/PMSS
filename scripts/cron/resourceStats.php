#!/usr/bin/env php
<?php
/**
 * Gather per-user resource usage and calculate statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/resources.php';
require_once '/scripts/lib/resources/processor.php';

$processor = new ResourceStatsProcessor(new resourceStatistics());
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
