#!/usr/bin/env php
<?php
/**
 * Cron entrypoint for storage health snapshots.
 *
 * Writes JSONL snapshots for SMART/NVMe/MD RAID without waking standby disks.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';

pmssPrepareCliEntrypoint(true, ['--quiet']);

require_once __DIR__.'/../util/storageHealthSnapshot.php';
