<?php
/** Managed skeleton delivery when the account cannot allocate a replacement. @license GPL-3.0-only */

require_once dirname(__DIR__, 2).'/quotaSnapshotCron.php';
require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once dirname(__DIR__).'/diskSpace.php';
require_once dirname(__DIR__, 2).'/runtime/environment.php';

/**
 * Return a root-measured delivery reason, null, or the non-deliverable 'root reserve low'.
 * Operations accept the measured path; repquota returns stdout lines or null.
 * Failed quota observations are cached too: never rerun repquota for each file.
 *
 * @param array<string,callable> $ops Optional mount, repquota, freeBytes and rootFreeBytes readers.
 */
function pmssUpdateUserRootStageEligible(string $user, string $home, int $bytes, array $ops = []): ?string
{
    if ($bytes < 0) return null;
    $root = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        $mountRead = $ops['mount'] ?? static function (string $path): ?string {
            $result = pmssCommandCapture('stat -c %m -- '.escapeshellarg($path));
            return $result['rc'] === 0 ? trim($result['stdout']) : null;
        };
        $quotaRead = $ops['repquota'] ?? static function (string $mount): ?array {
            $binary = pmssCommandPath('repquota');
            if ($binary === '') return null;
            $result = pmssCommandCapture(escapeshellarg($binary).' -u -n '.escapeshellarg($mount));
            return $result['rc'] === 0 ? explode("\n", $result['stdout']) : null;
        };
        $mount = $mountRead($root);
        $lines = is_string($mount) && $mount !== '' ? $quotaRead($mount) : null;
        if ($lines === null) {
            // Say once why over-quota accounts fall back to the free-space test only.
            $log = $ops['log'] ?? (function_exists('logMessage') ? 'logMessage' : 'error_log');
            $log('[update] Quota observation unavailable (repquota missing or failed); root-stage delivery uses the free-space test only');
        }
        foreach (pmssQuotaSnapshotParseRepquotaUserRows($lines ?? []) as $row) {
            $rows[$row[0]] = $row;
        }
    }

    // Identity comes from passwd, never from a customer file or account stderr.
    $account = function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
    $row = is_array($account) ? ($rows[$account['uid']] ?? null) : null;
    $reason = null;
    if ($row !== null) {
        $used = (float) $row[1];
        $soft = (float) $row[2];
        $hard = (float) $row[3];
        $needKiB = ceil($bytes / 1024) + 1;
        if (($hard > 0 && $used + $needKiB > $hard) || ($soft > 0 && $used >= $soft)) {
            $reason = 'over quota';
        }
    }
    $freeRead = $ops['freeBytes'] ?? static function (string $path) { return @disk_free_space($path); };
    $free = $freeRead($home);
    if ($reason === null && $free !== false && $free !== null && $free < $bytes + 65536) {
        $reason = 'home full';
    }
    if ($reason === null) return null;

    // Preserve the reserve for every root delivery, including over-quota accounts.
    $rootRead = $ops['rootFreeBytes'] ?? 'pmssRootUsableDiskMeasure';
    $rootFree = $rootRead($root);
    return $rootFree !== null && $rootFree !== false && $rootFree > $bytes + 256 * 1024 * 1024
        ? $reason : 'root reserve low';
}

/** Publish root:<primary group> 0755; the existing final permission pass hands it back. */
function pmssUpdateUserRootStageDeliver(string $user, string $target, string $content, ?string &$failure = null): bool
{
    $failure = 'account primary group unavailable';
    $account = function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
    $group = is_array($account) && function_exists('posix_getgrgid') ? posix_getgrgid($account['gid']) : false;
    if (!is_array($group) || !isset($group['name']) || $group['name'] === '') return false;
    return pmssRootStageFileWrite($target, $content, $group['name'], 0755, false, $failure);
}

/**
 * Record a delivery result, or drain one user's counters at the end of its skeleton pass.
 * @return array{0:int,1:int} Successful deliveries and failures respectively.
 */
function pmssUpdateUserRootStageCounts(string $user, ?bool $delivered = null): array
{
    static $counts = [];
    if ($delivered !== null) {
        if (!isset($counts[$user])) $counts[$user] = [0, 0];
        ++$counts[$user][$delivered ? 0 : 1];
        return $counts[$user];
    }
    $result = $counts[$user] ?? [0, 0];
    unset($counts[$user]);
    return $result;
}
