#!/usr/bin/env php
<?php
/**
 * Interactive user data migration helper.
 *
 * Wrapper around the implementation in `scripts/lib/userTransfer.php`.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2015-2025 - All Rights reserved.
 * @since 31/03/2015
 * @version 2.0
 *
 * @license GPL-3.0-only
 */

defined('PMSS_LOG_FILE') || define('PMSS_LOG_FILE', '/var/log/pmss/userTransfer.log');

require_once __DIR__.'/../lib/userTransfer.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserTransferMain');
