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
 */

require_once __DIR__.'/helpers.php';

/**
 * Apply or refresh the user-specific traffic cap.
 */
function userApplyTrafficLimit(array $user): void
{
    if (empty($user['trafficLimit']) || $user['trafficLimit'] <= 1) {
        return;
    }

    $cmd = sprintf(
        '/scripts/util/userTrafficLimit.php %s %s',
        escapeshellarg($user['name']),
        escapeshellarg($user['trafficLimit'])
    );
    userRunCommand('Updating traffic limit', $cmd);
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
    userRunCommand('Applying disk quota', $cmd);

    // Immediately refresh the user-visible quota status file
    $quotaFile = "/home/{$user['name']}/.quota";
    $refreshCmd = sprintf(
        'rm -f %1$s; quota -u %2$s -s >> %1$s; chmod o+r %1$s',
        escapeshellarg($quotaFile),
        escapeshellarg($user['name'])
    );
    userRunCommand('Refreshing quota status file', $refreshCmd);
}
