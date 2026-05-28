<?php
/**
 * User resource listing helpers.
 *
 * Keeps the CLI entrypoint thin while centralising formatting and data
 * assembly rules in one testable module.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../cli/optionParser.php';
require_once __DIR__.'/../systemdSliceProperties.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/traffic.php';
require_once __DIR__.'/trafficLimit.php';
require_once __DIR__.'/userConfigStore.php';

/** Render the canonical help text for userResourcesList.php. */
function pmssUserResourcesListUsageText(): string
{
    $useColor = pmssCliHelpSupportsColor();
    $lines = [
        pmssCliHelpHeading('Usage', $useColor),
        '  /scripts/util/userResourcesList.php [--brief|--full] [--json|--jsonl]',
        '',
        pmssCliHelpHeading('Options', $useColor),
        pmssCliHelpLine('--brief', 'Show the compact RAM/CPU/IO table.'.pmssCliHelpDim(' (default)', $useColor)),
        pmssCliHelpLine('--full', 'Include disk quota, traffic, TasksMax, and suspension columns.'),
        pmssCliHelpLine('--json', 'Emit one JSON array instead of text output.'),
        pmssCliHelpLine('--jsonl', 'Emit one JSON object per line for pipelines.'),
        pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
        '',
        pmssCliHelpHeading('Examples', $useColor),
        '  /scripts/util/userResourcesList.php --full',
        '  /scripts/util/userResourcesList.php --json',
        '',
        pmssCliHelpHeading('Notes', $useColor),
        '  - Run as root when querying real slice data; --help works without root.',
        '  - --brief and --full are mutually exclusive.',
    ];

    return implode("\n", $lines);
}

/** Format a byte value in a compact binary unit. */
function pmssUserResourcesListBinaryFormat(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '-';
    }
    $units = ['B', 'K', 'M', 'G', 'T'];
    $pow = min((int) floor(log($bytes) / log(1024)), count($units) - 1);
    $rounded = round($bytes / (1 << (10 * $pow)), 1);
    return ((abs($rounded - (int) $rounded) < 0.05) ? (int) $rounded : $rounded).$units[$pow];
}

/** Format GiB values compactly for text output. */
function pmssUserResourcesListGiBFormat($value): string
{
    if ($value === null) {
        return '-';
    }
    $rounded = round((float) $value, 1);
    return ((abs($rounded - (int) $rounded) < 0.05) ? (int) $rounded : $rounded).'G';
}

/**
 * Compute quota- and suspension-derived fields from stored user config.
 *
 * @return array{disk_quota_gib:?int,disk_burst_gib:?int,inode_quota:?int,inode_burst:?int,suspended:bool}
 */
function pmssUserResourcesListQuotaState(?array $userConfig, bool $suspended): array
{
    $diskQuotaGiB = null;
    $diskBurstGiB = null;
    $inodeQuota = null;
    $inodeBurst = null;
    if (is_array($userConfig)) {
        if (isset($userConfig['quota']) && is_numeric($userConfig['quota'])) {
            $diskQuotaGiB = (int) $userConfig['quota'];
        }
        if (isset($userConfig['quotaBurst']) && is_numeric($userConfig['quotaBurst'])) {
            $diskBurstGiB = (int) $userConfig['quotaBurst'];
        }
        if ($diskBurstGiB === null && $diskQuotaGiB !== null) {
            $diskBurstGiB = (int) round($diskQuotaGiB * 1.25);
        }
        if ($diskQuotaGiB !== null && $diskQuotaGiB > 0) {
            $inodeQuota = max($diskQuotaGiB * 500, 15000);
            $inodeBurst = (int) floor($inodeQuota * 1.25);
        }
        if (!$suspended && isset($userConfig['suspended'])) {
            $suspended = (bool) $userConfig['suspended'];
        }
    }
    return [
        'disk_quota_gib' => $diskQuotaGiB,
        'disk_burst_gib' => $diskBurstGiB,
        'inode_quota' => $inodeQuota,
        'inode_burst' => $inodeBurst,
        'suspended' => $suspended,
    ];
}

/** Build one resource entry from systemd properties and local PMSS state. */
function pmssUserResourcesListEntryBuild(string $user, array $info, array $props, ?array $userConfig): array
{
    $quotaState = pmssUserResourcesListQuotaState($userConfig, is_dir("/home/{$user}/www-disabled"));
    $trafficLimitPath = "/home/{$user}/.trafficLimit";
    $trafficDataPath = "/home/{$user}/.trafficData";
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
        'disk_quota_gib' => $quotaState['disk_quota_gib'],
        'disk_burst_gib' => $quotaState['disk_burst_gib'],
        'inode_quota' => $quotaState['inode_quota'],
        'inode_burst' => $quotaState['inode_burst'],
        'network_limit_gib' => (($trafficLimitGiB = pmssTrafficLimitReadGiBFile($trafficLimitPath)) > 0) ? $trafficLimitGiB : null,
        'network_used_gib' => round(pmssReadUserTrafficMonth($trafficDataPath) / 1024, 1),
        'process_max' => pmssSystemdPropertyTrailingInt($props['TasksMax']),
        'suspended' => $quotaState['suspended'],
    ];
}

/** Render a text row for the chosen mode. */
function pmssUserResourcesListRowBuild(array $resourceData, string $displayMode): array
{
    $row = [
        substr($resourceData['user'], 0, 10),
        (string) $resourceData['uid'],
        pmssUserResourcesListBinaryFormat($resourceData['memory_high']),
        pmssUserResourcesListBinaryFormat($resourceData['memory_max']),
        $resourceData['cpu_weight'] === null ? '-' : (string) $resourceData['cpu_weight'],
        $resourceData['cpu_quota_percent'] === null ? '-' : $resourceData['cpu_quota_percent'].'%',
        $resourceData['io_weight'] === null ? '-' : (string) $resourceData['io_weight'],
        pmssUserResourcesListBinaryFormat($resourceData['io_read_bandwidth']),
        pmssUserResourcesListBinaryFormat($resourceData['io_write_bandwidth']),
        $resourceData['io_read_iops'] === null ? '-' : (string) $resourceData['io_read_iops'],
        $resourceData['io_write_iops'] === null ? '-' : (string) $resourceData['io_write_iops'],
    ];
    if ($displayMode !== 'full') {
        return $row;
    }
    return array_merge($row, [
        $resourceData['disk_quota_gib'] === null ? '-' : pmssUserResourcesListGiBFormat($resourceData['disk_quota_gib']),
        $resourceData['disk_burst_gib'] === null ? '-' : pmssUserResourcesListGiBFormat($resourceData['disk_burst_gib']),
        $resourceData['inode_quota'] === null ? '-' : (string) $resourceData['inode_quota'],
        $resourceData['inode_burst'] === null ? '-' : (string) $resourceData['inode_burst'],
        $resourceData['network_limit_gib'] === null ? 'inf' : pmssUserResourcesListGiBFormat($resourceData['network_limit_gib']),
        pmssUserResourcesListGiBFormat($resourceData['network_used_gib']),
        $resourceData['process_max'] === null ? 'inf' : (string) $resourceData['process_max'],
        $resourceData['suspended'] ? 'yes' : 'no',
    ]);
}

/** Main CLI entrypoint for resource listing. */
function pmssUserResourcesListMain(array $argv): int
{
    $parsed = pmssParseCliTokens($argv);
    if (pmssCliHelpTextEmitIfRequested($parsed, pmssUserResourcesListUsageText()."\n")) return 0;
    if (posix_getuid() !== 0) {
        fwrite(STDERR, "Error: This script must be run as root to query systemd slices.\n");
        return 1;
    }
    $outputJsonl = (bool) pmssCliOption($parsed, 'jsonl');
    $outputJson = !$outputJsonl && (bool) pmssCliOption($parsed, 'json');
    $briefMode = (bool) pmssCliOption($parsed, 'brief');
    $fullMode = (bool) pmssCliOption($parsed, 'full');
    if ($briefMode && $fullMode) {
        fwrite(STDERR, "Error: choose either --brief or --full (not both).\n");
        return 1;
    }
    $format = $fullMode
        ? "%-10s %-5s %-8s %-8s %-6s %-6s %-6s %-6s %-6s %-7s %-7s %-6s %-6s %-7s %-7s %-7s %-7s %-8s %-9s\n"
        : "%-10s %-5s %-8s %-8s %-6s %-6s %-6s %-6s %-6s %-7s %-7s\n";
    $headers = $fullMode
        ? ["User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS", "DskQ", "DskB", "InoQ", "InoB", "NetLim", "NetUsed", "ProcMax", "Suspended"]
        : ["User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS"];
    $separator = $fullMode ? 180 : 120;
    $users = pmssListManagedUsers('/scripts/listUsers.php');
    if ($users === []) {
        echo $outputJson ? "[]\n" : ($outputJsonl ? '' : "No users found.\n");
        return 0;
    }
    if (!$outputJson && !$outputJsonl) {
        printf($format, ...$headers);
        echo str_repeat('-', $separator)."\n";
    }
    $allData = [];
    $sliceKeys = ['MemoryHigh', 'MemoryMax', 'CPUWeight', 'IOWeight', 'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota', 'IOReadBandwidthMax', 'IOWriteBandwidthMax', 'IOReadIOPSMax', 'IOWriteIOPSMax', 'TasksMax'];
    $store = new UserConfigStore();
    foreach ($users as $user) {
        if (($info = pmssUserAccountLookup($user)) === null) {
            continue;
        }
        $resourceData = pmssUserResourcesListEntryBuild($user, $info, pmssReadSystemdProperties('user-'.$info['uid'].'.slice', $sliceKeys), $store->get($user));
        if ($outputJsonl) {
            echo json_encode($resourceData)."\n";
        } elseif ($outputJson) {
            $allData[] = $resourceData;
        } else {
            printf($format, ...pmssUserResourcesListRowBuild($resourceData, $fullMode ? 'full' : 'brief'));
        }
    }
    if ($outputJson) {
        return pmssJsonEmitPayload($allData, 'Failed to encode user resources JSON.', JSON_PRETTY_PRINT);
    }
    return 0;
}
