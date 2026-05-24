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

pmssRunCliProcessorEntrypoint(__FILE__, new TrafficStatsProcessor(new trafficStatistics()));
