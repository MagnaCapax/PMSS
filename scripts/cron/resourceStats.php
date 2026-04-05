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

exit($processor->runCli($argv, $_SERVER['argv'][0]));
