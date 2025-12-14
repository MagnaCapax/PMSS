#!/usr/bin/php
<?php
/**
 * Cron entrypoint for storage health snapshots.
 *
 * Writes JSONL snapshots for SMART/NVMe/MD RAID without waking standby disks.
 */

require_once __DIR__.'/../lib/runtime.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

requireRoot();

$argv[] = '--quiet';

require_once __DIR__.'/../util/storageHealthSnapshot.php';
