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

if (!defined('PMSS_LOG_FILE')) {
    define('PMSS_LOG_FILE', '/var/log/pmss/userTransfer.log');
}

// Best-effort log directory creation so interactive runs keep their history.
if (!is_dir('/var/log/pmss')) {
    @mkdir('/var/log/pmss', 0755, true);
}

// Safety rail: userTransfer performs filesystem and ownership operations and
// must only run as root. Keep the message aligned with the library helper.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "This script must be run as root\n");
    exit(1);
}

require_once __DIR__.'/../lib/userTransfer.php';

pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');

exit(pmssUserTransferMain($argv));
