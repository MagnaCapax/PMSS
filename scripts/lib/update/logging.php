<?php
/**
 * Logging helpers shared by PMSS update routines.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!defined('PMSS_LOG_FILE')) {
    $pmssLogDir = function_exists('pmssLogDir') ? pmssLogDir() : rtrim((string) (getenv('PMSS_LOG_DIR') ?: '/var/log/pmss'), '/');
    define('PMSS_LOG_FILE', ($pmssLogDir !== '' ? $pmssLogDir : '/var/log/pmss').'/update.log');
}

$GLOBALS['PMSS_LOGMSG_USES_LOGMESSAGE'] = true;
$GLOBALS['PMSS_JSON_LOG_PATH'] = $GLOBALS['PMSS_JSON_LOG_PATH'] ?? null;
$GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $GLOBALS['PMSS_CORRELATION_ID_CACHE'] ?? null;

require_once __DIR__.'/../log.php';

if (!function_exists('pmssBuildCorrelationId')) {
    /**
     * Build a human-readable correlation ID for update/runtime logs.
     */
    function pmssBuildCorrelationId(): string
    {
        $timestamp = gmdate('Ymd-His');
        $hostRaw = function_exists('gethostname') ? (string) @gethostname() : (string) php_uname('n');
        $host = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $hostRaw));
        $host = trim($host, '-');
        if ($host === '') {
            $host = 'host';
        }

        try {
            $random = bin2hex(random_bytes(3));
        } catch (\Throwable $throwable) {
            $random = substr(hash('sha256', $timestamp.$host.microtime(true)), 0, 6);
        }

        return $timestamp.'-'.$host.'-'.$random;
    }
}

if (!function_exists('pmssCorrelationId')) {
    /**
     * Resolve or initialize a shared correlation ID for this process tree.
     */
    function pmssCorrelationId(bool $createIfMissing = true): string
    {
        if (is_string($GLOBALS['PMSS_CORRELATION_ID_CACHE']) && $GLOBALS['PMSS_CORRELATION_ID_CACHE'] !== '') {
            return $GLOBALS['PMSS_CORRELATION_ID_CACHE'];
        }

        if (($envValue = trim((string) (getenv('PMSS_CORRELATION_ID') ?: ''))) !== '') {
            return $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $envValue;
        }

        if (!$createIfMissing) {
            return '';
        }

        $generated = $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = pmssBuildCorrelationId();
        putenv('PMSS_CORRELATION_ID='.$generated);
        return $generated;
    }
}

if (!function_exists('pmssJsonLogPath')) {
    function pmssJsonLogPath(): string
    {
        if ($GLOBALS['PMSS_JSON_LOG_PATH'] !== null) {
            return $GLOBALS['PMSS_JSON_LOG_PATH'];
        }

        return $GLOBALS['PMSS_JSON_LOG_PATH'] = (string) (getenv('PMSS_JSON_LOG') ?: '');
    }
}

if (!function_exists('pmssLogJson')) {
    function pmssLogJson(array $payload): void
    {
        if (($path = pmssJsonLogPath()) === '') {
            return;
        }
        $payload['ts'] = $payload['ts'] ?? date('c');
        $correlationId = pmssCorrelationId();
        if ($correlationId !== '') {
            $payload['pmss_correlation_id'] = $payload['pmss_correlation_id'] ?? $correlationId;
        }
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
        return $logger ?: 'logMessage';
    }
}
