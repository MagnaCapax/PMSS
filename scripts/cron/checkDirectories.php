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

// Create log + var directories if they don't exist.
//
// NOTE: this list is the belt to pmssEnsureSafeDir's suspenders — every
// runtime/log dir that a PMSS cron script creates on-the-fly via
// pmssEnsureSafeDir() should ALSO be listed here. pmssEnsureSafeDir uses
// path-safety walks that have historically blocked /var/run/* creation due
// to the Debian /var/run -> /run symlink; this hourly cron uses plain
// mkdir() which bypasses that check and guarantees the directories exist
// even when the safety-helper logic regresses. See 2026-05-23 heshtok
// pathSafety.php regression for the precedent.
$requiredDirectories = array(
    '/var/log/pmss',
    '/var/log/pmss/traffic',
    '/var/log/pmss/traffic-ingress',
    '/var/log/pmss/cgroup',
    '/var/log/pmss/trafficStats',
    '/var/log/pmss/resources',
    '/var/run/pmss',
    '/var/run/pmss/api',
    '/var/run/pmss/trafficLimits',
    '/var/run/pmss/trafficStats',
    '/var/run/pmss/trafficIngress',
    '/var/run/pmss/resources',
    '/var/run/pmss/resourceStats',
    '/var/run/pmss/process-watchdog',
);

foreach($requiredDirectories AS $thisDir) {
    if (!file_exists($thisDir)) {
        if (!@mkdir($thisDir)) {
            $logger->msg("WARN: failed to create $thisDir");
            continue;
        }
        $logger->msg("Created $thisDir");
    } elseif (!is_dir($thisDir)) {
        // A stale plain file squatting the path would otherwise get chmod 0700
        // and silently keep blocking the directory forever.
        $logger->msg("WARN: $thisDir exists but is not a directory; skipping");
        continue;
    }
    // Ensure the directory is usable by root
    chown($thisDir, 'root');
    // Directories need the execute bit to be traversable
    chmod($thisDir, 0700);
}
