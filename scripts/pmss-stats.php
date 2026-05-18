#!/usr/bin/env php
<?php
/**
 * PMSS per-account terminal stats command.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

$pmssStatsLib = __DIR__.'/lib/pmssStats.php';
if (!is_file($pmssStatsLib)) {
    fwrite(STDERR, "Error: pmss stats library missing; aborting.\n");
    exit(1);
}

require_once $pmssStatsLib;

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssStatsMain');
