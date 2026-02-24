<?php
/**
 * Traffic- and quota-related helpers for user provisioning.
 *
 * Expected $user keys:
 *   - `name` (string) – Unix account.
 *   - `trafficLimit` (int|null) – Monthly quota in GiB; <=1 disables throttling.
 *   - `quota` (int) – Disk quota in GiB; converted to blocks/inodes below.
 *   - `CPUWeight` / `IOWeight` (optional ints) – Set elsewhere but carried with
 *     the user array for holistic resource adjustments.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';

/**
 * Apply or refresh the user-specific traffic cap.
 */
function userApplyTrafficLimit(array $user): void
{
    if (empty($user['trafficLimit']) || $user['trafficLimit'] <= 1) {
        return;
    }

    $cmd = sprintf(
        '/scripts/util/userTrafficLimit.php --user=%s --limit=%s',
        escapeshellarg($user['name']),
        escapeshellarg($user['trafficLimit'])
    );
    runStep('Updating traffic limit', $cmd);
}

/**
 * Configure disk quota limits for the user.
 */
function userApplyDiskQuota(array $user): void
{
    $filesLimitPerGb = 500;
    $quota = $user['quota'] * 1024 * 1024;
    $filesLimit = max($user['quota'] * $filesLimitPerGb, 15000);
    $quotaBurst = (int) floor($quota * 1.25);
    $filesBurst = (int) floor($filesLimit * 1.25);

    $cmd = sprintf(
        'setquota %s %d %d %d %d -a',
        escapeshellarg($user['name']),
        $quota,
        $quotaBurst,
        $filesLimit,
        $filesBurst
    );
    runStep('Applying disk quota', $cmd);

    // Immediately refresh the user-visible quota status file
    $refreshCmd = sprintf(
        'quota -u %2$s -s > %1$s; chmod o+r %1$s',
        escapeshellarg("/home/{$user['name']}/.quota"),
        escapeshellarg($user['name'])
    );
    runStep('Refreshing quota status file', $refreshCmd);
}

/**
 * Resolve the per-user torrent upload throttle file path.
 */
function pmssTorrentThrottlePath(string $username): string
{
    $homeDir = getenv('PMSS_HOME_DIR') ?: '/home';
    return rtrim($homeDir, '/').'/'.$username.'/.torrentThrottle';
}

/**
 * Read a root-owned torrent upload throttle (KiB/s) for the user.
 *
 * Returns:
 * - null when the file is missing or invalid
 * - 0 when the file explicitly requests unlimited
 * - >0 for the desired KiB/s cap
 */
function pmssReadTorrentThrottle(string $username): ?int
{
    $path = pmssTorrentThrottlePath($username);
    if (!is_file($path) || is_link($path)) {
        return null;
    }

    $stats = @stat($path);
    if ($stats === false) {
        return null;
    }

    $mode = $stats['mode'] & 0777;
    if (($mode & 0022) !== 0) { // group/other writable
        return null;
    }

    $testMode = getenv('PMSS_TEST_MODE') === '1';
    if (!$testMode) {
        if ((int) $stats['uid'] !== 0) {
            return null;
        }
        if (function_exists('posix_getgrgid')) {
            $group = @posix_getgrgid((int) $stats['gid']);
            if (is_array($group) && isset($group['name'])) {
                $groupName = $group['name'];
                if ($groupName !== 'root' && $groupName !== $username) {
                    return null;
                }
            }
        }
    }

    $raw = trim((string) @file_get_contents($path));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    $value = (int) $raw;
    return $value > 0 ? $value : 0;
}

/**
 * Write or remove a per-user torrent upload throttle (KiB/s).
 */
function pmssWriteTorrentThrottle(string $username, int $value): bool
{
    $path = pmssTorrentThrottlePath($username);
    $homeDir = dirname($path);
    if (!is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    if ($value <= 0) {
        if (is_link($path)) {
            return false;
        }
        return !is_file($path) || @unlink($path);
    }

    if (is_link($path)) {
        return false;
    }

    $tmp = $path.'.tmp';
    if (@file_put_contents($tmp, (string) $value) === false) {
        return false;
    }
    @chmod($tmp, 0640);

    $isRoot = function_exists('posix_geteuid') ? (posix_geteuid() === 0) : false;
    if ($isRoot) {
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
    } elseif (getenv('PMSS_TEST_MODE') !== '1') {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}
