#!/usr/bin/env php
<?php
/**
 * Gather per-user ingress traffic usage and calculate statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/traffic/processor.php';

$paths = [
    'traffic_dir'  => '/var/log/pmss/traffic-ingress',
    'runtime_dir'  => '/var/run/pmss/trafficIngress',
    'traffic_mode' => 'ingress',
];

$stats = new trafficStatistics($paths);
(new TrafficStorage($paths))->ensureRuntime();
$processor = new TrafficStatsProcessor($stats, $paths);

pmssRunCliEntrypointWithArgv(__FILE__, static function (array $argv) use ($processor): int { return $processor->runCli($argv, (string) ($argv[0] ?? __FILE__)); });
