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
    if ($thresholdBytes < 0 || !pmssUserFilePathIsSafe($path) || !is_file($path) || is_link($path)) {
        return ['status' => 'skip', 'reason' => 'unsafe_target'];
    }

    $pathStat = @lstat($path);
    if (!is_array($pathStat) || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000) {
        return ['status' => 'skip', 'reason' => 'not_regular_file'];
    }

    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) return ['status' => 'error', 'reason' => 'open_failed'];
    if (!@flock($handle, LOCK_EX | LOCK_NB)) { @fclose($handle); return ['status' => 'skip', 'reason' => 'lock_busy']; }

    $handleStat = @fstat($handle);
    if (
        !is_array($handleStat)
        || ($pathStat['dev'] ?? null) !== ($handleStat['dev'] ?? null)
        || ($pathStat['ino'] ?? null) !== ($handleStat['ino'] ?? null)
    ) { @fclose($handle); return ['status' => 'skip', 'reason' => 'path_changed']; }

    if (($handleStat['nlink'] ?? 1) !== 1) { @fclose($handle); return ['status' => 'skip', 'reason' => 'multiple_links']; }

    $sizeBefore = (int) ($handleStat['size'] ?? 0);
    if ($sizeBefore <= $thresholdBytes) { @fclose($handle); return ['status' => 'skip', 'reason' => 'below_threshold', 'sizeBefore' => $sizeBefore]; }

    if (!@ftruncate($handle, 0)) { @fclose($handle); return ['status' => 'error', 'reason' => 'truncate_failed', 'sizeBefore' => $sizeBefore]; }

    @fflush($handle);
    @fclose($handle);
    clearstatcache(true, $path);

    return ['status' => 'trimmed', 'sizeBefore' => $sizeBefore, 'sizeAfter' => (int) @filesize($path)];
}
