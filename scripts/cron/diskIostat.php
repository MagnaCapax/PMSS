#!/usr/bin/env php
<?php
/**
 * Cron task: disk Iostat.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/diskIostat.php';

// Hold the handle for the whole run so the five-minute cron cadence cannot overlap.
$pmssDiskIostatLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-diskIostat.lock'), true);
if ($pmssDiskIostatLock === false) {
    echo date('Y-m-d H:i:s').": diskIostat already running; skipping\n";
    exit(0);
}

pmssRunCliEntrypoint(__FILE__, 'pmssDiskIostatMain');
