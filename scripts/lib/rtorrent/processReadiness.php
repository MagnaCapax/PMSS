<?php
/** rTorrent startup stability, reboot grace and deterministic staggering. @license Proprietary */

require_once __DIR__.'/processRows.php';

/**
 * Wait for a PID set to appear, then verify at least one original PID survives.
 *
 * The caller supplies a probe callback so tests can stay hermetic. Success only
 * occurs when the same observed PID still exists after the stability window.
 *
 * @param callable $pidProvider             Callback returning candidate PIDs.
 * @param float    $startupTimeoutSeconds   Seconds to wait for first appearance.
 * @param float    $stabilityWindowSeconds  Seconds the same PID must survive.
 * @param int      $pollMicroseconds        Poll interval in microseconds.
 *
 * @return bool True when at least one initially observed PID survives.
 */
function rtorrentProcessWaitForStablePids(
    callable $pidProvider,
    float $startupTimeoutSeconds,
    float $stabilityWindowSeconds,
    int $pollMicroseconds = 250000
): bool {
    $pollMicroseconds = max(1000, $pollMicroseconds);
    $startupTimeoutSeconds = max(0.0, $startupTimeoutSeconds);
    $stabilityWindowSeconds = max(0.0, $stabilityWindowSeconds);

    $initialPids = rtorrentProcessNormalizePids($pidProvider());
    if (empty($initialPids) && $startupTimeoutSeconds > 0.0) {
        $startupDeadline = microtime(true) + $startupTimeoutSeconds;
        while (empty($initialPids) && microtime(true) < $startupDeadline) {
            usleep($pollMicroseconds);
            $initialPids = rtorrentProcessNormalizePids($pidProvider());
        }
    }

    if (empty($initialPids)) {
        return false;
    }

    if ($stabilityWindowSeconds > 0.0) {
        $stabilityDeadline = microtime(true) + $stabilityWindowSeconds;
        while (microtime(true) < $stabilityDeadline) {
            $remainingMicroseconds = (int) (($stabilityDeadline - microtime(true)) * 1000000);
            if ($remainingMicroseconds <= 0) {
                break;
            }
            usleep(min($pollMicroseconds, $remainingMicroseconds));
        }
    }

    $currentPids = rtorrentProcessNormalizePids($pidProvider());
    return !empty(array_intersect($initialPids, $currentPids));
}

/**
 * Check if system was recently rebooted.
 *
 * Reads /proc/uptime to determine seconds since boot. Used to detect
 * post-reboot conditions and trigger staggered starts.
 *
 * @param int $threshold Seconds threshold (default: 600 = 10 minutes).
 *
 * @return bool True if uptime is less than threshold.
 */
function rtorrentProcessRecentReboot(int $threshold = 600): bool
{
    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime === false) {
        return false;
    }
    // /proc/uptime format: "12345.67 98765.43" (uptime idle_time)
    $parts = explode(' ', trim($uptime));
    $seconds = (float) $parts[0];
    return $seconds > 0 && $seconds < $threshold;
}

/**
 * Calculate a deterministic delay for a user.
 *
 * Uses crc32 hash of username to generate a consistent but distributed delay.
 * This ensures the same user always gets the same delay, but different users
 * are spread across the time window.
 *
 * @param string $user      Username.
 * @param int    $maxDelay  Maximum delay in seconds (default: 300 = 5 minutes).
 *
 * @return int Delay in seconds (0 to $maxDelay).
 */
function rtorrentProcessStaggerDelay(string $user, int $maxDelay = 300): int
{
    $hash = crc32($user);
    return ($hash < 0 ? $hash & 0x7FFFFFFF : $hash) % ($maxDelay + 1);
}
