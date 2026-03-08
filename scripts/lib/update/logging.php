<?php
/**
 * Logging helpers shared by PMSS update routines.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!defined('PMSS_LOG_FILE')) {
    $pmssLogDir = function_exists('pmssLogDir') ? pmssLogDir() : rtrim((string) (getenv('PMSS_LOG_DIR') ?: '/var/log/pmss'), '/');
    if ($pmssLogDir === '') {
        $pmssLogDir = '/var/log/pmss';
    }
    define('PMSS_LOG_FILE', $pmssLogDir.'/update.log');
}

$GLOBALS['PMSS_JSON_LOG_PATH'] = $GLOBALS['PMSS_JSON_LOG_PATH'] ?? null;

if (!function_exists('pmssJsonLogPath')) {
    function pmssJsonLogPath(): string
    {
        if ($GLOBALS['PMSS_JSON_LOG_PATH'] === null) {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = getenv('PMSS_JSON_LOG') ?: '';
        }
        return $GLOBALS['PMSS_JSON_LOG_PATH'];
    }
}

if (!function_exists('pmssLogJson')) {
    function pmssLogJson(array $payload): void
    {
        if (($path = pmssJsonLogPath()) === '') {
            return;
        }
        $payload['ts'] = $payload['ts'] ?? date('c');
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('logMessage')) {
    function logMessage(string $message, array $context = []): void
    {
        // Strip ANSI codes for file logging.
        $cleanMessage = preg_replace('/\x1b\[[0-9;]*m/', '', $message);
        @file_put_contents(PMSS_LOG_FILE, date('[Y-m-d H:i:s] ').$cleanMessage.PHP_EOL, FILE_APPEND | LOCK_EX);
        // Stdout gets the colored message.
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
        return $logger ?? 'logMessage';
    }
}
