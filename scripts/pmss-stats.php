#!/usr/bin/env php
<?php
/**
 * PMSS per-account terminal stats command.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/lib/pmssStats.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

exit(pmssStatsMain($argv));
