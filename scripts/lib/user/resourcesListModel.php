<?php
/** Configured resource entry projection and its systemd/traffic dependencies.
 * @license GPL-3.0-only
 */
require_once dirname(__DIR__).'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['../systemdSliceProperties.php', 'selection.php', 'traffic.php', 'trafficLimit.php', 'userConfigStore.php']);

/**
 * Compute quota- and suspension-derived fields from stored user config.
 *
 * @return array{disk_quota_gib:?int,disk_burst_gib:?int,inode_quota:?int,inode_burst:?int,suspended:bool}
 */
function pmssUserResourcesListQuotaState(?array $userConfig, bool $suspended): array
{
    $diskQuotaGiB = is_numeric($userConfig['quota'] ?? null) ? (int) $userConfig['quota'] : null;
    $inodeQuota = $diskQuotaGiB !== null && $diskQuotaGiB > 0 ? max($diskQuotaGiB * 500, 15000) : null;
    return [
        'disk_quota_gib' => $diskQuotaGiB,
        // Explicit zero bursts survive; only missing/invalid values derive from the quota.
        'disk_burst_gib' => is_numeric($userConfig['quotaBurst'] ?? null) ? (int) $userConfig['quotaBurst']
            : ($diskQuotaGiB === null ? null : (int) round($diskQuotaGiB * 1.25)),
        'inode_quota' => $inodeQuota,
        'inode_burst' => $inodeQuota === null ? null : (int) floor($inodeQuota * 1.25),
        'suspended' => $suspended || (bool) ($userConfig['suspended'] ?? false),
    ];
}

/** Build one resource entry from systemd properties and local PMSS state. */
function pmssUserResourcesListEntryBuild(string $user, array $info, array $props, ?array $userConfig): array
{
    $quotaState = pmssUserResourcesListQuotaState($userConfig, is_dir("/home/{$user}/www-disabled"));
    return [
        'user' => $user,
        'uid' => $info['uid'],
        'memory_high' => pmssSystemdPropertyTrailingInt($props['MemoryHigh']),
        'memory_max' => pmssSystemdPropertyTrailingInt($props['MemoryMax']),
        'cpu_weight' => is_numeric($props['CPUWeight']) ? (int) $props['CPUWeight'] : null,
        'cpu_quota_percent' => pmssSystemdCpuQuotaPercent($props),
        'io_weight' => is_numeric($props['IOWeight']) ? (int) $props['IOWeight'] : null,
        'io_read_bandwidth' => pmssSystemdPropertyTrailingInt($props['IOReadBandwidthMax']),
        'io_write_bandwidth' => pmssSystemdPropertyTrailingInt($props['IOWriteBandwidthMax']),
        'io_read_iops' => pmssSystemdPropertyTrailingInt($props['IOReadIOPSMax']),
        'io_write_iops' => pmssSystemdPropertyTrailingInt($props['IOWriteIOPSMax']),
    ] + array_diff_key($quotaState, ['suspended' => true]) + [
        'network_limit_gib' => (($trafficLimitGiB = pmssTrafficLimitReadGiBFile("/home/{$user}/.trafficLimit")) > 0) ? $trafficLimitGiB : null,
        'network_used_gib' => round(pmssReadUserTrafficMonth("/home/{$user}/.trafficData") / 1024, 1),
        'process_max' => pmssSystemdPropertyTrailingInt($props['TasksMax']),
        'suspended' => $quotaState['suspended'],
    ];
}
