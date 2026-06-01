<?php
/**
 * Monthly I/O operations limit helpers.
 *
 * Persistence paths:
 * - /etc/seedbox/runtime/iopsLimits/<user> (consumed by scripts/cron/iopsLimits.php)
 * - /home/<user>/.iopsLimit (user-visible limit file)
 *
 * Resource usage source:
 * - /etc/seedbox/runtime/resourceStats/<user> or /home/<user>/.resourceData
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/integerSetting.php';

const PMSS_IOPS_LIMIT_MAX_MONTHLY_OPERATIONS = 1.0E14;
const PMSS_IOPS_LIMIT_SENTINEL_FLOOR = 9.0E18;

/** @param mixed $raw */
function pmssIopsLimitParseMonthlyOperations($raw, ?string &$error = null): ?int
{
    return pmssIntegerSettingParseNonNegative($raw, 'ops', $error);
}

function pmssIopsLimitRuntimePath(string $username, ?string $runtimeDir = null): string
{
    return pmssIntegerSettingRuntimeUserPath('iopsLimits', $username, $runtimeDir);
}

function pmssIopsLimitPath(string $username, ?string $homeDir = null): string
{
    return pmssIntegerSettingUserHomePath($username, '.iopsLimit', $homeDir);
}

/** @return array<string,int> */
function pmssIopsLimitTargetModes(string $username, ?string $homeDir = null, ?string $runtimeDir = null): array
{
    return [
        pmssIopsLimitRuntimePath($username, $runtimeDir) => 0600,
        pmssIopsLimitPath($username, $homeDir) => 0664,
    ];
}

/** @param mixed $raw */
function pmssIopsLimitMonthlyUsageValue($raw): float
{
    if (!is_numeric($raw)) {
        return 0.0;
    }

    $value = (float) $raw;
    return $value >= 0.0
        && $value <= PMSS_IOPS_LIMIT_MAX_MONTHLY_OPERATIONS
        && $value < PMSS_IOPS_LIMIT_SENTINEL_FLOOR
        ? $value
        : 0.0;
}

function pmssReadUserMonthlyIopsUsage(string $path): int
{
    $data = pmssReadSerializedArrayFile($path);
    if ($data === null) {
        return 0;
    }

    $read = pmssIopsLimitMonthlyUsageValue($data['io_read_ops']['raw']['month'] ?? null);
    $write = pmssIopsLimitMonthlyUsageValue($data['io_write_ops']['raw']['month'] ?? null);

    return (int) round($read + $write);
}
