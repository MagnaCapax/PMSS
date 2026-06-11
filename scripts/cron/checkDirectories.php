#!/usr/bin/env php
<?php
/**
 * Cron task: check Directories.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Checks and creates required temp directories used by other cron jobs.

require_once __DIR__.'/../lib/runtime/directories.php';

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(pmssCheckDirectoriesMain());
}
