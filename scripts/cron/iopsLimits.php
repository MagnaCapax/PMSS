#!/usr/bin/env php
<?php
/**
 * Refresh per-user monthly IOPS throttling.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/log.php';
require_once __DIR__.'/../lib/user/iopsLimitEnforcer.php';

exit(pmssIopsLimitsRun());
