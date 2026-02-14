#!/usr/bin/env php
<?php
/**
 * Cron task: resource snapshot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once '/scripts/lib/resources/snapshot.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssResourceSnapshotRun());
}
