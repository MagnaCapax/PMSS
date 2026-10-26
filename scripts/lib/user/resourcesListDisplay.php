<?php
/** Pure configured-resource column catalog and text formatting.
 * @license GPL-3.0-only
 */
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

/** One ordered catalog supplies headers, widths and formatted values together. */
function pmssUserResourcesListColumns(array $data, string $displayMode): array
{
    return array_slice([
        ['User', 10, substr($data['user'] ?? '', 0, 10)],
        ['UID', 5, (string) ($data['uid'] ?? '')],
        ['MemHigh', 8, pmssUserResourcesListBinaryFormat($data['memory_high'] ?? null)],
        ['MemMax', 8, pmssUserResourcesListBinaryFormat($data['memory_max'] ?? null)],
        ['CPUWt', 6, (string) ($data['cpu_weight'] ?? '-')],
        ['CPUQt', 6, isset($data['cpu_quota_percent']) ? $data['cpu_quota_percent'].'%' : '-'],
        ['BlkWt', 6, (string) ($data['io_weight'] ?? '-')],
        ['RdBW', 6, pmssUserResourcesListBinaryFormat($data['io_read_bandwidth'] ?? null)],
        ['WrBW', 6, pmssUserResourcesListBinaryFormat($data['io_write_bandwidth'] ?? null)],
        ['RdIOPS', 7, (string) ($data['io_read_iops'] ?? '-')],
        ['WrIOPS', 7, (string) ($data['io_write_iops'] ?? '-')],
        // Every other legacy mode keeps the eleven-column prefix.
        ['DskQ', 6, pmssUserResourcesListGiBFormat($data['disk_quota_gib'] ?? null)],
        ['DskB', 6, pmssUserResourcesListGiBFormat($data['disk_burst_gib'] ?? null)],
        ['InoQ', 7, (string) ($data['inode_quota'] ?? '-')],
        ['InoB', 7, (string) ($data['inode_burst'] ?? '-')],
        ['NetLim', 7, isset($data['network_limit_gib']) ? pmssUserResourcesListGiBFormat($data['network_limit_gib']) : 'inf'],
        ['NetUsed', 7, pmssUserResourcesListGiBFormat($data['network_used_gib'] ?? null)],
        ['ProcMax', 8, (string) ($data['process_max'] ?? 'inf')],
        ['Suspended', 9, !empty($data['suspended']) ? 'yes' : 'no'],
    ], 0, $displayMode === 'full' ? null : 11);
}

/** Preserve the row helper while deriving its values from the column catalog. */
function pmssUserResourcesListRowBuild(array $resourceData, string $displayMode): array
{
    return array_column(pmssUserResourcesListColumns($resourceData, $displayMode), 2);
}
