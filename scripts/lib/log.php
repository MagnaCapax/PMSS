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

if (!function_exists('logmsg')) {
    /**
     * Historical logging function retained for backwards compatibility.
     */
    function logmsg(string $message): void
    {
        if (!empty($GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE']) && function_exists('logMessage')) {
            logMessage($message);
            return;
        }

        if (!isset($GLOBALS['logmsg_default_logger'])) {
            require_once __DIR__.'/logger.php';
            $GLOBALS['logmsg_default_logger'] = new Logger($_SERVER['SCRIPT_NAME'] ?? __FILE__);
        }

        $GLOBALS['logmsg_default_logger']->msg($message);
    }
}
