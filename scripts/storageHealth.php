#!/usr/bin/env php
<?php
/**
 * Operator-facing storage health report.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/storageHealth/report.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssStorageHealthReportMain');
