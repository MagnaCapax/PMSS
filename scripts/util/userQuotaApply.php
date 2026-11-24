#!/usr/bin/env php
<?php
/**
 * Set one account's disk quota in the kernel and in the user store (ADR 0342).
 *
 * Usage: userQuotaApply.php USERNAME QUOTA_GiB
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/quotaApplyCli.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserQuotaApplyCli');
