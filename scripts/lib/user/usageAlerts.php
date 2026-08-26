<?php
/**
 * Per-user usage-alert measurement and state helpers.
 *
 * Customer opt-in is a user-owned marker; measurements and notification state
 * remain root-owned so a tenant cannot redirect mail or suppress a new alert.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';
require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';
require_once dirname(__DIR__).'/stats/collect.php';
require_once dirname(__DIR__).'/traffic/storage.php';
require_once __DIR__.'/trafficLimit.php';

const PMSS_USAGE_ALERTS_TRAFFIC_PERCENT = 80.0;
const PMSS_USAGE_ALERTS_DISK_PERCENT = 90.0;

/** Return threshold details keyed by stable notification-state names. */
function pmssUsageAlertsConditionsBuild(?float $trafficPercent, ?float $diskPercent, array $mediaStatus): array
{
    $conditions = [];
    if ($trafficPercent !== null && $trafficPercent >= PMSS_USAGE_ALERTS_TRAFFIC_PERCENT) {
        $conditions['traffic'] = sprintf('Traffic usage is %.1f%% of the monthly allowance.', $trafficPercent);
    }
    if ($diskPercent !== null && $diskPercent >= PMSS_USAGE_ALERTS_DISK_PERCENT) {
        $conditions['disk'] = sprintf('Disk usage is %.1f%% of quota.', $diskPercent);
    }

    $failed = [];
    foreach (($mediaStatus['apps'] ?? []) as $app => $status) {
        if (is_array($status) && ($status['state'] ?? '') === 'failed') {
            $label = trim((string) ($status['label'] ?? $app));
            $failed[] = preg_replace('/[^A-Za-z0-9 ._-]/', '', $label) ?: (string) $app;
        }
    }
    if ($failed !== []) {
        sort($failed, SORT_NATURAL | SORT_FLAG_CASE);
        $conditions['service'] = 'Monitored services reported down: '.implode(', ', $failed).'.';
    }

    return $conditions;
}

/** Allow writes only by root, including legacy root-group-writable artifacts. */
function pmssUsageAlertsRootArtifactMetadataIsTrusted(array $stat): bool
{
    $mode = (int) ($stat['mode'] ?? 0);
    return ($mode & 0170000) === 0100000
        && (int) ($stat['uid'] ?? -1) === 0
        && ($mode & 0002) === 0
        && (($mode & 0020) === 0 || (int) ($stat['gid'] ?? -1) === 0);
}

/** Root-produced customer artifacts must remain non-writable by tenants. */
function pmssUsageAlertsRootArtifactIsTrusted(string $path): bool
{
    $stat = @lstat($path);
    return is_array($stat) && pmssUsageAlertsRootArtifactMetadataIsTrusted($stat);
}

/** Read the current alert conditions from existing PMSS artifacts. */
function pmssUsageAlertsConditionsRead(string $user, string $homeRoot = '/home', string $runtimeRoot = '/etc/seedbox/runtime'): array
{
    if (!pmssValidateUsername($user)) return [];
    $home = rtrim($homeRoot, '/').'/'.$user;
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) return [];

    $trafficPercent = null;
    $traffic = pmssTrafficReadRootOwnedStatsPayload($home.'/.trafficData', $user);
    $limitPath = rtrim($runtimeRoot, '/').'/trafficLimits/'.$user;
    if ($traffic !== null && pmssUsageAlertsRootArtifactIsTrusted($limitPath)) {
        $bonusPath = pmssUsageAlertsRootArtifactIsTrusted($home.'/.bonusTraffic') ? $home.'/.bonusTraffic' : '';
        $limit = pmssTrafficLimitStateRead($limitPath, $bonusPath)['effectiveLimitGiB'];
        if ($limit > 0) $trafficPercent = ((float) $traffic['raw']['month'] / ($limit * 1024.0)) * 100.0;
    }

    $diskPercent = null;
    if (pmssUsageAlertsRootArtifactIsTrusted($home.'/.quota')) {
        $quota = pmssStatsReadQuotaSnapshot($home);
        $diskPercent = pmssStatsPercent($quota['used_bytes'], $quota['soft_bytes']);
    }
    $mediaStatus = pmssUsageAlertsRootArtifactIsTrusted($home.'/.media-stack-status.json')
        ? (pmssJsonFileReadAssoc($home.'/.media-stack-status.json', true) ?? [])
        : [];

    return pmssUsageAlertsConditionsBuild($trafficPercent, $diskPercent, $mediaStatus);
}

/** Accept only the regular 0600 marker written by the account's panel process. */
function pmssUsageAlertsOptInEnabled(string $user, string $homeRoot = '/home'): bool
{
    $account = pmssValidateUsername($user) ? pmssUserAccountLookup($user) : null;
    $path = rtrim($homeRoot, '/').'/'.$user.'/.usageAlertsEnabled';
    $stat = @lstat($path);
    return is_array($account) && is_array($stat)
        && (($stat['mode'] ?? 0) & 0170000) === 0100000
        && (int) ($stat['uid'] ?? -1) === (int) ($account['uid'] ?? -2)
        && ((int) ($stat['mode'] ?? 0) & 0777) === 0600;
}

/** Resolve one root-owned per-threshold notification marker. */
function pmssUsageAlertsStatePath(string $user, string $key, string $stateDir): ?string
{
    return pmssValidateUsername($user) && in_array($key, ['traffic', 'disk', 'service'], true)
        ? rtrim($stateDir, '/').'/'.$user.'.'.$key
        : null;
}

/** Return active conditions that have not yet been successfully delivered. */
function pmssUsageAlertsNewConditions(string $user, array $active, string $stateDir): array
{
    $new = [];
    foreach ($active as $key => $message) {
        $path = pmssUsageAlertsStatePath($user, (string) $key, $stateDir);
        if ($path !== null && !is_file($path)) $new[$key] = $message;
    }
    return $new;
}

/** Remove cleared markers, or every marker when alerts are disabled. */
function pmssUsageAlertsStateClear(string $user, array $active, string $stateDir): void
{
    foreach (['traffic', 'disk', 'service'] as $key) {
        $path = pmssUsageAlertsStatePath($user, $key, $stateDir);
        if ($path !== null && !isset($active[$key]) && (is_file($path) || is_link($path)) && !@unlink($path)) {
            throw new RuntimeException('Usage alert notification state could not be cleared.');
        }
    }
}

/** Record delivery only after the transport returns successfully. */
function pmssUsageAlertsStateRecord(string $user, array $delivered, string $stateDir): bool
{
    if (!pmssEnsureSafeDir($stateDir, 0700)) return false;
    foreach (array_keys($delivered) as $key) {
        $path = pmssUsageAlertsStatePath($user, (string) $key, $stateDir);
        if ($path === null || !pmssWriteManagedFile($path, gmdate('c')."\n", 'root', 'root', 0600)) return false;
    }
    return true;
}
