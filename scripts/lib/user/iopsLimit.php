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

if (!function_exists('pmssIopsLimitParseMonthlyOperations')) {
    /** @param mixed $raw */
    function pmssIopsLimitParseMonthlyOperations($raw, ?string &$error = null): ?int
    {
        $error = null;

        if ($raw === null || $raw === false || $raw === true) {
            $error = ($raw === true) ? 'missing value' : 'missing';
            return null;
        }

        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                $error = 'empty';
                return null;
            }
            if (!preg_match('/^([0-9]+)(?:\s*ops)?$/i', $trim, $matches)) {
                $error = 'invalid format';
                return null;
            }
            $value = (int) $matches[1];
        } elseif (is_float($raw)) {
            if (floor($raw) != $raw) {
                $error = 'must be an integer';
                return null;
            }
            $value = (int) $raw;
        } else {
            $error = 'invalid type';
            return null;
        }

        if ($value < 0) {
            $error = 'must be >= 0';
            return null;
        }

        return $value;
    }
}

if (!function_exists('pmssIopsLimitReadOperationsFile')) {
    function pmssIopsLimitReadOperationsFile(string $path): int
    {
        $raw = pmssReadRegularFileTrimmed($path);
        if ($raw === null || $raw === '') {
            return 0;
        }

        $error = null;
        $value = pmssIopsLimitParseMonthlyOperations($raw, $error);
        return $value !== null ? $value : 0;
    }
}

if (!function_exists('pmssIopsLimitWriteOperationsFile')) {
    function pmssIopsLimitWriteOperationsFile(string $path, int $value): bool
    {
        return $value >= 0
            && function_exists('pmssAtomicWriteFile')
            && pmssAtomicWriteFile($path, (string) $value);
    }
}

if (!function_exists('pmssIopsLimitConvergeFileMode')) {
    function pmssIopsLimitConvergeFileMode(string $path, int $mode): bool
    {
        if ((!is_file($path) && !is_dir($path)) || is_link($path)) {
            return false;
        }

        if (@chmod($path, $mode)) {
            clearstatcache(true, $path);
            return true;
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        return $perms !== false && (($perms & 0777) === ($mode & 0777));
    }
}

if (!function_exists('pmssIopsLimitEnsureStorageDir')) {
    function pmssIopsLimitEnsureStorageDir(string $path): bool
    {
        if (!function_exists('pmssPathTargetIsSafe') || !pmssPathTargetIsSafe($path, true)) {
            return false;
        }

        if (function_exists('pmssEnsureDir')) {
            return pmssEnsureDir($path, 0700, 'root', 'root') && is_dir($path) && !is_link($path);
        }

        if (!pmssDirEnsureExists($path, 0755)) {
            return false;
        }

        if (!is_dir($path) || is_link($path)) {
            return false;
        }

        return pmssIopsLimitConvergeFileMode($path, 0700);
    }
}

if (!function_exists('pmssIopsLimitPersistTargetModes')) {
    /** @param array<string,int> $targetModes */
    function pmssIopsLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool
    {
        $error = null;
        if ($value < 0) {
            $error = 'invalid operations value';
            return false;
        }

        foreach ($targetModes as $target => $mode) {
            if (!pmssIopsLimitWriteOperationsFile($target, $value)) {
                $error = 'failed to write '.$target;
                return false;
            }
            if (!pmssIopsLimitConvergeFileMode($target, (int) $mode)) {
                $error = 'failed to secure '.$target;
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('pmssIopsLimitRuntimePath')) {
    function pmssIopsLimitRuntimePath(string $username, ?string $runtimeDir = null): string
    {
        $root = pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime');
        return rtrim($root, '/').'/iopsLimits/'.$username;
    }
}

if (!function_exists('pmssIopsLimitPath')) {
    function pmssIopsLimitPath(string $username, ?string $homeDir = null): string
    {
        $root = pmssDirPathResolve($homeDir, 'PMSS_HOME_DIR', '/home');
        return rtrim($root, '/').'/'.$username.'/.iopsLimit';
    }
}

if (!function_exists('pmssIopsLimitTargetModes')) {
    /** @return array<string,int> */
    function pmssIopsLimitTargetModes(string $username, ?string $homeDir = null, ?string $runtimeDir = null): array
    {
        return [
            pmssIopsLimitRuntimePath($username, $runtimeDir) => 0600,
            pmssIopsLimitPath($username, $homeDir) => 0664,
        ];
    }
}

if (!function_exists('pmssIopsLimitPrepareTargetModes')) {
    /** @param array<string,int> $targetModes */
    function pmssIopsLimitPrepareTargetModes(array $targetModes): bool
    {
        $runtimePath = array_key_first($targetModes);
        return is_string($runtimePath)
            && $runtimePath !== ''
            && pmssIopsLimitEnsureStorageDir(dirname($runtimePath));
    }
}

if (!function_exists('pmssReadUserMonthlyIopsUsage')) {
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
}
