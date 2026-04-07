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

if (!function_exists('pmssJsonLineAppend')) {
    /** Append one payload to a JSON Lines file. */
    function pmssJsonLineAppend(string $path, array $payload): bool
    {
        return is_string($encoded = json_encode($payload, JSON_UNESCAPED_SLASHES))
            && @file_put_contents($path, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('pmssLogAppendTimestampedLine')) {
    /** Append one timestamped line to a log file. */
    function pmssLogAppendTimestampedLine(string $path, string $message, string $timestampFormat = '[Y-m-d H:i:s] ', string $prefix = '', ?int $mode = null): bool
    {
        $written = @file_put_contents($path, date($timestampFormat).$prefix.$message.PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
        $written && $mode !== null && @chmod($path, $mode);
        return $written;
    }
}
