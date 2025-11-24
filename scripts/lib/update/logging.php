<?php
/**
 * Logging helpers shared by PMSS update routines.
 */

if (!defined('PMSS_LOG_FILE')) {
    define('PMSS_LOG_FILE', '/var/log/pmss-update.log');
}

if (!isset($GLOBALS['PMSS_JSON_LOG_PATH'])) {
    $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
}

if (!function_exists('pmssJsonLogPath')) {
    function pmssJsonLogPath(): string
    {
        if ($GLOBALS['PMSS_JSON_LOG_PATH'] === null) {
            $candidate = getenv('PMSS_JSON_LOG') ?: '';
            $GLOBALS['PMSS_JSON_LOG_PATH'] = $candidate !== '' ? $candidate : '';
        }
        return $GLOBALS['PMSS_JSON_LOG_PATH'];
    }
}

if (!function_exists('pmssResetJsonLogPath')) {
    function pmssResetJsonLogPath(): void
    {
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
    }
}

if (!function_exists('pmssLogJson')) {
    function pmssLogJson(array $payload): void
    {
        $path = pmssJsonLogPath();
        if ($path === '') {
            return;
        }
        $payload['ts'] = $payload['ts'] ?? date('c');
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('logMessage')) {
    function logMessage(string $message, array $context = []): void
    {
        $ts = date('[Y-m-d H:i:s] ');
        
        // Strip ANSI codes for file logging
        $cleanMessage = preg_replace('/\x1b\[[0-9;]*m/', '', $message);
        @file_put_contents(PMSS_LOG_FILE, $ts.$cleanMessage.PHP_EOL, FILE_APPEND | LOCK_EX);
        
        // Stdout gets the colored message
        echo $message.PHP_EOL;
        
        pmssLogJson([
            'event'   => 'log',
            'message' => $cleanMessage,
            'context' => $context,
        ]);
    }
}

if (!function_exists('pmssSelectLogger')) {
    function pmssSelectLogger(?callable $logger = null): callable
    {
        if ($logger !== null && is_callable($logger)) {
            return $logger;
        }
        return 'logMessage';
    }
}
