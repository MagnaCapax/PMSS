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

/** @param mixed $raw */
function pmssIopsLimitParseMonthlyOperations($raw, ?string &$error = null): ?int
{
    return pmssIntegerSettingParseNonNegative($raw, 'ops', $error);
}

function pmssIopsLimitRuntimeUserPath(string $bucket, string $username, ?string $runtimeDir = null): string { return pmssIntegerSettingRuntimeUserPath($bucket, $username, $runtimeDir); }

function pmssIopsLimitRuntimePath(string $username, ?string $runtimeDir = null): string
{
    return pmssIopsLimitRuntimeUserPath('iopsLimits', $username, $runtimeDir);
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

function pmssReadUserMonthlyIopsUsage(string $path): int
{
    $data = pmssReadSerializedArrayFile($path);
    if ($data === null) {
        return 0;
    }

    $read = isset($data['io_read_ops']['raw']['month']) && is_numeric($data['io_read_ops']['raw']['month'])
        ? (float) $data['io_read_ops']['raw']['month']
        : 0.0;
    $write = isset($data['io_write_ops']['raw']['month']) && is_numeric($data['io_write_ops']['raw']['month'])
        ? (float) $data['io_write_ops']['raw']['month']
        : 0.0;

    return (int) round($read + $write);
}
