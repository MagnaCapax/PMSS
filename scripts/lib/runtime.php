<?php
/**
 * Shared runtime helpers for PMSS automation scripts.
 *
 * Provides consistent logging and command execution utilities so that
 * provisioning scripts can emit useful diagnostics without aborting on
 * recoverable errors.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php';

const PMSS_LOG_DIR_DEFAULT = '/var/log/pmss';
const PMSS_RUNTIME_DIR_DEFAULT = '/var/run/pmss';
const PMSS_STATE_DIR_DEFAULT = '/var/lib/pmss';
const PMSS_RUNTIME_FALLBACK_LOG = PMSS_LOG_DIR_DEFAULT.'/runtime.log';
const PMSS_COMMAND_TIMEOUT_DEFAULT = 1200;
const PMSS_COMMAND_TIMEOUT_APT_DEFAULT = 1200;
const PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT = 5;
const PMSS_TIMEOUT_FIRE_LOG_DEFAULT = '/var/log/pmss-timeout-fires.jsonl';
const PMSS_BLOCK_DATA_DEVICE_NAME_PATTERN = '/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/';
const PMSS_IOPING_PROBE_COUNT = 60;
const PMSS_IOPING_PROBE_INTERVAL = '0.1';
const PMSS_IOPING_PROBE_TIMEOUT = 15;

/**
 * Load static helper-module require lists relative to a bootstrap file.
 *
 * @param string[] $relativePaths
 */
function pmssRequireRelativeFiles(string $baseDir, array $relativePaths): void
{
    $baseDir = rtrim($baseDir, '/');
    foreach ($relativePaths as $relativePath) {
        require_once $baseDir.'/'.$relativePath;
    }
}

if (!function_exists('pmssFormatBytes')) {
    // Format byte counts with binary IEC units for compact human output.
    function pmssFormatBytes(float $bytes, int $precision = 1, int $minimumUnitIndex = 0): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $index = 0;
        $minimumUnitIndex = max(0, min($minimumUnitIndex, count($units) - 1));
        while ($index < $minimumUnitIndex && $index < count($units) - 1) {
            $bytes /= 1024.0;
            $index++;
        }
        while ($bytes >= 1024.0 && $index < count($units) - 1) {
            $bytes /= 1024.0;
            $index++;
        }

        return number_format($bytes, $index === 0 ? 0 : $precision, '.', '').' '.$units[$index];
    }
}

require_once __DIR__.'/runtime/environment.php';

// Share structured `logMessage()` with update helpers via the standalone
// logging bootstrap. `require_once` keeps the import idempotent. Only runtime-
// first callers should disable legacy `logmsg()` forwarding afterwards; update
// bootstraps set that state earlier and must keep it intact.
$pmssLogmsgUsesLogMessageInitialized = array_key_exists('PMSS_LOGMSG_USES_LOGMESSAGE', $GLOBALS);
require_once __DIR__.'/update/logging.php';
if (!$pmssLogmsgUsesLogMessageInitialized) {
    $GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE'] = false;
}

require_once __DIR__.'/runtime/filesystem.php';
require_once __DIR__.'/runtime/locks.php';
require_once __DIR__.'/runtime/system.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssRequireCli')) {
    /**
     * Enforce CLI execution for script entrypoints and reusable CLI flows.
     */
    function pmssRequireCli(string $message = 'This script must be run from the command line.', ?int $failureCode = 1): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        fwrite(STDERR, rtrim($message, "\r\n").PHP_EOL);
        if ($failureCode !== null) {
            exit($failureCode);
        }

        return false;
    }
}

require_once __DIR__.'/runtime/cli.php';
require_once __DIR__.'/runtime/snapshot.php';
