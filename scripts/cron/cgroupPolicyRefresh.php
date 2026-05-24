#!/usr/bin/env php
<?php
/**
 * Reapply explicit per-user io.latency/io.cost settings from stored config.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/cgroupPolicyRefresh.php';

pmssRunCliEntrypoint(__FILE__, 'pmssCgroupPolicyRefreshRun');
