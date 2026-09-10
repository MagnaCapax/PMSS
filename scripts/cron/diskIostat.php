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
$pmssDiskIostatLock = pmssCronLockAcquire('diskIostat', 'pmssCronLockSkipLog');

pmssRunCliEntrypoint(__FILE__, 'pmssDiskIostatMain');
