#!/usr/bin/env php
<?php
/**
 * Cron task: opt-in scheduled per-user config backup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/scheduledConfigBackup.php';

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(pmssScheduledConfigBackupMain($argv ?? []));
}
