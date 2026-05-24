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

pmssRunCliEntrypointWithArgv(__FILE__, static function (array $argv) use ($processor): int { return $processor->runCli($argv, (string) ($argv[0] ?? __FILE__)); });
