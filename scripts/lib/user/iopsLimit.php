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

require_once dirname(__DIR__).'/runtime.php';

if (is_file(dirname(__DIR__).'/lighttpd/userFileWrite.php')) {
    require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';
}

require_once __DIR__.'/integerSetting.php';

/** @param mixed $raw */
function pmssIopsLimitParseMonthlyOperations($raw, ?string &$error = null): ?int
{
    return pmssIntegerSettingParseNonNegative($raw, 'ops', $error);
}

function pmssIopsLimitReadOperationsFile(string $path): int
{
    return pmssIntegerSettingFileRead($path, 'pmssIopsLimitParseMonthlyOperations');
}

function pmssIopsLimitWriteOperationsFile(string $path, int $value): bool
{
    return pmssIntegerSettingFileWrite($path, $value);
}

function pmssIopsLimitConvergeFileMode(string $path, int $mode): bool
{
    return pmssIntegerSettingPathModeConverge($path, $mode);
}

function pmssIopsLimitEnsureStorageDir(string $path): bool
{
    return pmssIntegerSettingStorageDirEnsure($path, 0700);
}

/** @param array<string,int> $targetModes */
function pmssIopsLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool
{
    return pmssIntegerSettingTargetModesPersist($targetModes, $value, $error, 'invalid operations value');
}

function pmssIopsLimitRuntimePath(string $username, ?string $runtimeDir = null): string
{
    $root = pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime');
    return rtrim($root, '/').'/iopsLimits/'.$username;
}

function pmssIopsLimitPath(string $username, ?string $homeDir = null): string
{
    $root = pmssDirPathResolve($homeDir, 'PMSS_HOME_DIR', '/home');
    return rtrim($root, '/').'/'.$username.'/.iopsLimit';
}

/** @return array<string,int> */
function pmssIopsLimitTargetModes(string $username, ?string $homeDir = null, ?string $runtimeDir = null): array
{
    return [
        pmssIopsLimitRuntimePath($username, $runtimeDir) => 0600,
        pmssIopsLimitPath($username, $homeDir) => 0664,
    ];
}

/** @param array<string,int> $targetModes */
function pmssIopsLimitPrepareTargetModes(array $targetModes): bool
{
    $runtimePath = array_key_first($targetModes);
    return is_string($runtimePath)
        && $runtimePath !== ''
        && pmssIopsLimitEnsureStorageDir(dirname($runtimePath));
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
