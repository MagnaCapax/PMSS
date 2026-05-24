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

pmssRunCliEntrypointWithArgv(__FILE__, static function (array $argv) use ($processor): int { return $processor->runCli($argv, (string) ($argv[0] ?? __FILE__)); });
