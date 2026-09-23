#!/usr/bin/env php
<?php
/**
 * Cron task: opt-in scheduled per-user config backup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/scheduledConfigBackup.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssScheduledConfigBackupMain');
