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
pmssRequireRelativeFiles(__DIR__, ['../lighttpd/userFileWrite.php', '../traffic/storage.php', 'integerSetting.php']);

/**
 * Apply or refresh the user-specific traffic cap.
 */
function userApplyTrafficLimit(array $user): void
{
    if (!isset($user['name']) || !is_string($user['name']) || !pmssUsernameIsValid($user['name'])) {
        return;
    }

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
    if (!isset($user['name']) || !is_string($user['name']) || !pmssUsernameIsValid($user['name'])) {
        return;
    }

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

    $quotaPath = pmssUserQuotaStatusPath($user['name']);
    if ($quotaPath === null) {
        pmssLogStatus('skip', 'Refreshing quota status file: unsafe or missing user home');
        return;
    }

    // Keep redirection as the only shell feature so quota output lands in ~/.quota.
    runStep(
        'Refreshing quota status file',
        pmssBuildCommand('quota', ['-u', $user['name'], '-s']).' > '.escapeshellarg($quotaPath)
    );
    runStep('Publishing quota status file', pmssBuildCommand('chmod', ['o+r', $quotaPath]));
}

/**
 * Resolve the per-user quota snapshot path without crossing unsafe home paths.
 */
function pmssUserQuotaStatusPath(string $username): ?string
{
    if (!pmssUsernameIsValid($username)) {
        return null;
    }

    $path = pmssUserHomeFilePath($username, '.quota');
    $home = dirname($path);
    if (!is_dir($home) || is_link($home) || !pmssPathTargetIsSafe($home, true)) {
        return null;
    }

    return pmssUserFilePathIsSafe($path) ? $path : null;
}

/**
 * Read monthly traffic usage from a serialized user traffic data file.
 *
 * Returns the raw monthly total rounded to the nearest integer, or 0 when the
 * file is missing, symlinked, or invalid.
 */
function pmssReadUserTrafficMonth(string $path): int
{
    $data = pmssReadSerializedArrayFile($path);
    if ($data === null || !isset($data['raw']['month']) || !is_numeric($data['raw']['month'])) {
        return 0;
    }

    return (int) round($data['raw']['month']);
}

/** @return array<string,int> */
function pmssReadUserTrafficStates(string $username): array
{
    if (!pmssUsernameIsValid($username)) {
        return [];
    }

    $totals = [];
    foreach (array_intersect_key(pmssTrafficDataPaths($username), ['normal' => true, 'local' => true, 'ingress' => true]) as $name => $path) {
        $totals[$name] = pmssReadUserTrafficMonth($path);
    }

    return $totals;
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
    if (!pmssUsernameIsValid($username)) {
        return null;
    }

    $path = pmssUserHomeFilePath($username, '.torrentThrottle');
    if (!pmssRegularFilePathIsReadable($path)) {
        return null;
    }

    $stats = @stat($path);
    if ($stats === false) {
        return null;
    }

    if (($stats['mode'] & 0022) !== 0) { // group/other writable
        return null;
    }

    if (!pmssTestModeEnabled()) {
        if ((int) $stats['uid'] !== 0) {
            return null;
        }
        if (function_exists('posix_getgrgid')
            && is_array($group = @posix_getgrgid((int) $stats['gid']))
            && isset($group['name'])
            && !in_array($group['name'], ['root', $username], true)
        ) {
            return null;
        }
    }

    $raw = pmssReadRegularFileTrimmed($path);
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
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
    if (!pmssUsernameIsValid($username)) {
        return false;
    }

    $path = pmssUserHomeFilePath($username, '.torrentThrottle');
    $homeDir = dirname($path);
    if (!is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    if ($value <= 0) {
        return pmssIntegerSettingFileRemove($path);
    }

    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        return false;
    }

    $isRoot = function_exists('posix_geteuid') ? (posix_geteuid() === 0) : false;
    if (!$isRoot && !pmssTestModeEnabled()) {
        return false;
    }

    return pmssReplaceUserFileWithMetadata(
        $path,
        (string) $value,
        0640,
        $isRoot ? 'root' : null,
        $isRoot ? 'root' : null
    );
}
