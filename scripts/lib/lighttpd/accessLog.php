<?php
/**
 * Oversized per-user lighttpd access log helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userFileWrite.php';

if (!defined('PMSS_LIGHTTPD_ACCESS_LOG_THRESHOLD_BYTES')) {
    define('PMSS_LIGHTTPD_ACCESS_LOG_THRESHOLD_BYTES', 100 * 1024 * 1024);
}

if (!defined('PMSS_LIGHTTPD_ACCESS_LOG_MAX_AGE_SECONDS')) {
    // Privacy retention cap (ADR 0337): the log holds visitor addresses and request paths.
    define('PMSS_LIGHTTPD_ACCESS_LOG_MAX_AGE_SECONDS', 30 * 86400);
}

/**
 * True when the oldest entry of a locked access log is older than the retention cap.
 *
 * The first line is the oldest entry (the log is append-only), so its own timestamp bounds retention even while
 * the log is still being written; a first line without the default `[dd/Mon/yyyy:HH:MM:SS +zzzz]` stamp falls
 * back to the file mtime. An empty log or a disabled cap is never stale.
 *
 * @param resource $handle Locked handle positioned anywhere; it is rewound to the start.
 * @param array<string, int> $handleStat fstat() of the same handle.
 */
function pmssLighttpdAccessLogIsStale($handle, array $handleStat, ?int $maxAgeSeconds, ?int $now = null): bool
{
    if ($maxAgeSeconds === null || $maxAgeSeconds <= 0 || (int) ($handleStat['size'] ?? 0) === 0) return false;
    $line = @fseek($handle, 0) === 0 ? @fgets($handle, 4096) : false;
    $stamp = is_string($line) && preg_match('~\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]~', $line, $match) === 1
        ? \DateTime::createFromFormat('d/M/Y:H:i:s O', $match[1]) : false;
    $oldest = $stamp !== false ? $stamp->getTimestamp() : (int) ($handleStat['mtime'] ?? 0);
    return $oldest < ($now ?? time()) - $maxAgeSeconds;
}

/**
 * Truncate a regular lighttpd access log in place once it exceeds the size limit or its oldest entry exceeds the age cap.
 *
 * @return array<string, int|string>
 */
function pmssLighttpdAccessLogTrimFile(string $path, int $thresholdBytes, ?int $maxAgeSeconds = PMSS_LIGHTTPD_ACCESS_LOG_MAX_AGE_SECONDS, ?int $now = null): array
{
    if ($thresholdBytes < 0 || !pmssUserFilePathIsSafe($path) || !pmssRegularFilePathIsReadable($path)) {
        return ['status' => 'skip', 'reason' => 'unsafe_target'];
    }

    $pathStat = @lstat($path);
    if (!is_array($pathStat) || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000) {
        return ['status' => 'skip', 'reason' => 'not_regular_file'];
    }

    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) return ['status' => 'error', 'reason' => 'open_failed'];
    $result = null;
    $sizeBefore = null;
    $aged = false;
    try {
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            $result = ['status' => 'skip', 'reason' => 'lock_busy'];
        } else {
            $handleStat = null;
            if (!pmssLockFileHandleMatchesPath($handle, $path, $pathStat, $handleStat)) {
                $result = ['status' => 'skip', 'reason' => 'path_changed'];
            } elseif (($handleStat['nlink'] ?? 1) !== 1) {
                $result = ['status' => 'skip', 'reason' => 'multiple_links'];
            } elseif (($sizeBefore = (int) ($handleStat['size'] ?? 0)) <= $thresholdBytes
                && !($aged = pmssLighttpdAccessLogIsStale($handle, $handleStat, $maxAgeSeconds, $now))) {
                $result = ['status' => 'skip', 'reason' => 'below_threshold', 'sizeBefore' => $sizeBefore];
            } elseif (!@ftruncate($handle, 0)) {
                $result = ['status' => 'error', 'reason' => 'truncate_failed', 'sizeBefore' => $sizeBefore];
            } elseif (!@fflush($handle)) {
                $result = ['status' => 'error', 'reason' => 'flush_failed', 'sizeBefore' => $sizeBefore];
            } else {
                $result = ['status' => 'trimmed', 'sizeBefore' => $sizeBefore] + ($aged ? ['reason' => 'age'] : []);
            }
        }
    } finally {
        // The lock belongs to this handle; every return and throwable must release it.
        $closed = @fclose($handle);
    }

    if (!$closed) {
        $result = ['status' => 'error', 'reason' => 'close_failed']
            + ($sizeBefore === null ? [] : ['sizeBefore' => $sizeBefore]);
    }
    if (($result['status'] ?? '') !== 'trimmed') return $result;

    clearstatcache(true, $path);
    $result['sizeAfter'] = (int) @filesize($path);
    return $result;
}
