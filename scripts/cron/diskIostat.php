#!/usr/bin/env php
<?php
/**
 * Cron task: disk Iostat.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/diskIostat.php';

pmssRunCliEntrypoint(__FILE__, 'pmssDiskIostatMain');
