#!/usr/bin/env php
<?php
/**
 * PMSS per-account terminal stats command.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/lib/pmssStats.php';

pmssRequireCli();
exit(pmssStatsMain($argv ?? ($_SERVER['argv'] ?? [])));
