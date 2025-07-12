#!/usr/bin/php
<?php
// Checks and creates required temp directories used by other cron jobs.
const LOG_FILE     = '/var/log/pmss/checkDirectories.log';
const FALLBACK_LOG = '/tmp/checkDirectories.log';

require_once '/scripts/lib/logger.php';
$logger = new Logger(__FILE__);

$logger->msg('Verifying required directories');

// Create log + var directories if they don't exist
$requiredDirectories = array(
    '/var/log/pmss',
    '/var/log/pmss/traffic',
    '/var/log/pmss/cgroup',
    '/var/log/pmss/trafficStats',
    '/var/log/pmss/ioStats',
    '/var/run/pmss',
    '/var/run/pmss/api',
    '/var/run/pmss/trafficLimits',
    '/var/run/pmss/ioStats',
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


