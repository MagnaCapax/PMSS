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
    /** Historical logging function retained for backwards compatibility. */
    function logmsg(string $message): void
    {
        if (!empty($GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE']) && function_exists('logMessage')) {
            logMessage($message);
            return;
        }

        $defaults = isset($GLOBALS['PMSS_LOGMSG_DEFAULTS']) && is_array($GLOBALS['PMSS_LOGMSG_DEFAULTS']) ? $GLOBALS['PMSS_LOGMSG_DEFAULTS'] : [];
        $script = trim((string) ($defaults['script'] ?? '')); $script = $script !== '' ? $script : ($_SERVER['SCRIPT_NAME'] ?? __FILE__);
        $baseName = trim((string) ($defaults['base_name'] ?? '')); $baseName = $baseName !== '' ? $baseName : basename($script, '.php');
        $primary = rtrim(trim((string) ($defaults['dir'] ?? '')) !== '' ? (string) $defaults['dir'] : '/var/log/pmss', '/').'/'.$baseName.'.log';
        $fallback = rtrim(trim((string) ($defaults['fallback_dir'] ?? '')) !== '' ? (string) $defaults['fallback_dir'] : '/tmp', '/').'/'.$baseName.'.log';
        pmssLogWriteMessage($primary, $fallback, $message, !empty($defaults['write_to_stderr']));
    }
}

if (!function_exists('pmssJsonEncodeSafe')) {
    /**
     * Encode a payload as JSON while tolerating invalid UTF-8.
     */
    function pmssJsonEncodeSafe(array $payload, int $flags = 0): ?string
    {
        $jsonFlags = $flags;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $encoded = json_encode($payload, $jsonFlags);
        return is_string($encoded) ? $encoded : null;
    }
}

if (!function_exists('pmssJsonLineAppend')) {
    /** Append one payload to a JSON Lines file. */
    function pmssJsonLineAppend(string $path, array $payload): bool
    {
        return is_string($encoded = pmssJsonEncodeSafe($payload, JSON_UNESCAPED_SLASHES))
            && @file_put_contents($path, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('pmssJsonEmitPayload')) {
    /** Emit one JSON payload to stdout while keeping encode failures on stderr. */
    function pmssJsonEmitPayload(array $payload, string $errorMessage, int $flags = 0): int
    {
        if (!is_string($encoded = pmssJsonEncodeSafe($payload, $flags))) {
            fwrite(STDERR, rtrim($errorMessage, "\n").PHP_EOL);
            return 1;
        }

        echo $encoded.PHP_EOL;
        return 0;
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

if (!function_exists('pmssLogWriteMessage')) {
    /** Mirror one message into PMSS log files and the active console stream. */
    function pmssLogWriteMessage(string $primary, string $fallback, string $message, bool $writeToStderr = false): void
    {
        pmssLogAppendTimestampedLine($primary, $message) || pmssLogAppendTimestampedLine($fallback, $message);
        $writeToStderr ? fwrite(STDERR, $message.PHP_EOL) : print($message.PHP_EOL);
    }
}
