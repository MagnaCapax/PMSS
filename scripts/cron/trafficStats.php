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
require_once '/scripts/lib/runtime.php';

// Serialize runs with the canonical in-script lock (ADR-0049), replacing the
// former root.cron `flock -xn`. Preserves the prior serialization of the stats
// aggregation (ran under flock before); non-blocking: skip if a run holds the lock.
$trafficStatsLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-trafficStats.lock'), true);
if ($trafficStatsLock === false) { fwrite(STDERR, "trafficStats already running; skipping\n"); exit(0); }

// Keep the legacy runtime-preparation side effect before worker dispatch.
(new TrafficStorage())->ensureRuntime();

pmssRunCliProcessorEntrypoint(__FILE__, new TrafficStatsProcessor(new trafficStatistics()));
