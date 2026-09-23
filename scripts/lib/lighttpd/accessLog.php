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

/**
 * Truncate a regular lighttpd access log in place once it exceeds the limit.
 *
 * @return array<string, int|string>
 */
function pmssLighttpdAccessLogTrimFile(string $path, int $thresholdBytes): array
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
    try {
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            $result = ['status' => 'skip', 'reason' => 'lock_busy'];
        } else {
            $handleStat = null;
            if (!pmssLockFileHandleMatchesPath($handle, $path, $pathStat, $handleStat)) {
                $result = ['status' => 'skip', 'reason' => 'path_changed'];
            } elseif (($handleStat['nlink'] ?? 1) !== 1) {
                $result = ['status' => 'skip', 'reason' => 'multiple_links'];
            } elseif (($sizeBefore = (int) ($handleStat['size'] ?? 0)) <= $thresholdBytes) {
                $result = ['status' => 'skip', 'reason' => 'below_threshold', 'sizeBefore' => $sizeBefore];
            } elseif (!@ftruncate($handle, 0)) {
                $result = ['status' => 'error', 'reason' => 'truncate_failed', 'sizeBefore' => $sizeBefore];
            } elseif (!@fflush($handle)) {
                $result = ['status' => 'error', 'reason' => 'flush_failed', 'sizeBefore' => $sizeBefore];
            } else {
                $result = ['status' => 'trimmed', 'sizeBefore' => $sizeBefore];
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
