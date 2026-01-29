#!/usr/bin/env php
<?php
/**
 * Cron task: check Directories.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Checks and creates required temp directories used by other cron jobs.

require_once '/scripts/lib/logger.php';
$logger = new Logger(__FILE__);

$logger->msg('Verifying required directories');

// Create log + var directories if they don't exist
$requiredDirectories = array(
    '/var/log/pmss',
    '/var/log/pmss/traffic',
    '/var/log/pmss/cgroup',
    '/var/log/pmss/trafficStats',
    '/var/run/pmss',
    '/var/run/pmss/api',
    '/var/run/pmss/trafficLimits',
);

foreach($requiredDirectories AS $thisDir) {
    if (!file_exists($thisDir)) {
        mkdir($thisDir);
        $logger->msg("Created $thisDir");
    }
    // Ensure the directory is usable by root
    chown($thisDir, 'root');
    // Directories need the execute bit to be traversable
    chmod($thisDir, 0700);
}
