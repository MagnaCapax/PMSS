<?php
/**
 * Traffic- and quota-related helpers for user provisioning.
 *
 * Expected $user keys:
 *   - `name` (string) – Unix account.
 *   - `trafficLimit` (int|null) – Monthly quota in GiB; <=1 disables throttling.
 *   - `quota` (int) – Disk quota in GiB; converted to blocks/inodes below.
 *   - `blockSoftKiB` / `blockHardKiB` (optional ints) – Block limits to keep as they are: when
 *     both are set, `setquota` receives them instead of the limits derived from `quota`, and
 *     `quota` then only sizes the file limits (ADR 0342 re-apply, which reads them live).
 *   - `CPUWeight` / `IOWeight` (optional ints) – Set elsewhere but carried with
 *     the user array for holistic resource adjustments.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__, ['../lighttpd/userFileWrite.php', '../traffic/storage.php', 'integerSetting.php', 'quotaFilesPerGiB.php', 'userConfigStore.php']);
require_once __DIR__.'/../quotaSnapshot.php';

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
 *
 * The file limits come from the host's per-GiB ratio (quotaFilesPerGiB.php, ADR 0342).
 *
 * @return bool True when the limits were applied; the quota status file refresh does not count.
 */
function userApplyDiskQuota(array $user): bool
{
    if (!isset($user['name']) || !is_string($user['name']) || !pmssUsernameIsValid($user['name'])) {
        return false;
    }

    $quota = $user['quota'] * 1024 * 1024;
    $filesLimits = pmssQuotaFilesLimit((int) $user['quota']);
    $filesLimit = $filesLimits['soft'];
    $quotaBurst = (int) floor($quota * 1.25);
    // Block limits the caller read from the kernel are passed back unchanged (ADR 0342 re-apply).
    if (isset($user['blockSoftKiB'], $user['blockHardKiB'])) {
        $quota = (int) $user['blockSoftKiB'];
        $quotaBurst = (int) $user['blockHardKiB'];
    }
    $filesBurst = $filesLimits['hard'];

    $cmd = sprintf(
        'setquota %s %d %d %d %d -a',
        escapeshellarg($user['name']),
        $quota,
        $quotaBurst,
        $filesLimit,
        $filesBurst
    );
    $applied = runStep('Applying disk quota', $cmd) === 0;

    $quotaPath = pmssUserQuotaStatusPath($user['name']);
    if ($quotaPath === null) {
        pmssLogStatus('skip', 'Refreshing quota status file: unsafe or missing user home');
        return $applied;
    }

    $report = pmssQuotaSnapshotAggregateReport($user['name'],
        pmssQuotaReportCapture(pmssBuildCommand('quota', ['-u', $user['name'], '-s']).' 2>&1'));
    if ($report['timed_out'] || $report['rc'] > 1 || $report['lines'] === []
        || !pmssQuotaSnapshotWrite($quotaPath, implode("\n", $report['lines'])."\n")) {
        pmssLogStatus('error', "Refreshing quota status file failed for {$user['name']}: {$quotaPath}");
    }

    return $applied;
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
    $month = $data === null ? null : pmssTrafficRawMonthMiB($data);
    return $month === null ? 0 : (int) round($month);
}

/** @return array<string,int> */
function pmssReadUserTrafficStates(string $username): array
{
    if (!pmssUsernameIsValid($username)) {
        return [];
    }

    $totals = [];
    // Root copies (ADR 0213); the files in the home are display copies.
    $paths = ['normal' => pmssTrafficStatsPath($username), 'local' => pmssTrafficStatsPath($username.'-localnet'), 'ingress' => pmssTrafficIngressStatsPath($username)];
    foreach ($paths as $name => $path) {
        $totals[$name] = pmssReadUserTrafficMonth($path);
    }

    return $totals;
}

/**
 * The torrent upload cap (KiB/s) for the user from the root store (ADR 0213), or null when
 * none is set. userConfig.php --upload-throttle-kib records it; ~/.torrentThrottle is its
 * display copy.
 */
function pmssReadTorrentThrottle(string $username): ?int
{
    if (!pmssUsernameIsValid($username)) {
        return null;
    }
    $value = (new UserConfigStore())->getInt($username, 'uploadThrottleKib');
    return $value > 0 ? $value : null;
}

/**
 * Write or remove the display copy of the torrent upload cap (KiB/s).
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

    if (!pmssLockFilePathIsSafe($path)) {
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
