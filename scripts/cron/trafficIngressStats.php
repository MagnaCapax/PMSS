#!/usr/bin/env php
<?php
/**
 * Gather per-user ingress traffic usage and calculate statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/traffic/processor.php';
require_once '/scripts/lib/runtime.php';

// Serialize runs with the canonical in-script lock (ADR-0049), replacing the
// former root.cron `flock -xn`. Preserves the prior serialization of the ingress
// stats aggregation (ran under flock before); non-blocking: skip if a run holds it.
$trafficIngressStatsLock = pmssCronLockAcquire('trafficIngressStats');

$paths = [
    'traffic_dir'  => '/var/log/pmss/traffic-ingress',
    'runtime_dir'  => '/var/run/pmss/trafficIngress',
    'traffic_mode' => 'ingress',
];

(new TrafficStorage($paths))->ensureRuntime();

pmssRunCliProcessorEntrypoint(__FILE__, new TrafficStatsProcessor(new trafficStatistics($paths), $paths));
