<?php
/**
 * Shared legacy logging entry point for PMSS libraries.
 *
 * Keeps `logmsg()` available without forcing callers to pull the full update
 * bootstrap, while still forwarding into structured update logging whenever
 * that stack has already been loaded.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/pathSafety.php';
require_once __DIR__.'/runtime/filesystem.php';

require_once __DIR__.'/log/text.php';
require_once __DIR__.'/log/json.php';
require_once __DIR__.'/log/append.php';
require_once __DIR__.'/log/jsonFiles.php';

if (!function_exists('logmsg')) {
    /** Historical logging function retained for backwards compatibility. */
    function logmsg(string $message): void
    {
        if (!empty($GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE']) && function_exists('logMessage')) {
            logMessage($message);
            return;
        }

        $defaults = is_array($GLOBALS['PMSS_LOGMSG_DEFAULTS'] ?? null) ? $GLOBALS['PMSS_LOGMSG_DEFAULTS'] : [];
        $script = trim((string) ($defaults['script'] ?? '')) ?: ($_SERVER['SCRIPT_NAME'] ?? __FILE__);
        $baseName = trim((string) ($defaults['base_name'] ?? '')) ?: basename($script, '.php');
        $primary = rtrim(trim((string) ($defaults['dir'] ?? '')) !== '' ? (string) $defaults['dir'] : '/var/log/pmss', '/').'/'.$baseName.'.log';
        $fallback = rtrim(trim((string) ($defaults['fallback_dir'] ?? '')) !== '' ? (string) $defaults['fallback_dir'] : '/tmp', '/').'/'.$baseName.'.log';
        pmssLogWriteMessage($primary, $fallback, $message, !empty($defaults['write_to_stderr']));
    }
}
