#!/usr/bin/env php
<?php
/**
 * Gather per-user resource usage and calculate statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/resources/processor.php';

pmssRunCliProcessorEntrypoint(__FILE__, new ResourceStatsProcessor(new resourceStatistics()));
