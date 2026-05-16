<?php
/**
 * Shared helpers for user-facing integer settings.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

if (is_file(dirname(__DIR__).'/lighttpd/userFileWrite.php')) {
    require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';
}

if (!function_exists('pmssIntegerSettingParseNonNegative')) {
    /** @param mixed $raw */
    function pmssIntegerSettingParseNonNegative($raw, string $suffix = '', ?string &$error = null): ?int
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

            $suffixPattern = $suffix === '' ? '' : '(?:\s*'.preg_quote($suffix, '/').')?';
            if (!preg_match('/^([0-9]+)'.$suffixPattern.'$/i', $trim, $matches)) {
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

if (!function_exists('pmssIntegerSettingFileRead')) {
    function pmssIntegerSettingFileRead(string $path, callable $parser, int $default = 0): int
    {
        $raw = pmssReadRegularFileTrimmed($path);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $error = null;
        $value = $parser($raw, $error);
        return $value !== null ? (int) $value : $default;
    }
}

if (!function_exists('pmssIntegerSettingFileWrite')) {
    function pmssIntegerSettingFileWrite(string $path, int $value): bool
    {
        return $value >= 0
            && function_exists('pmssAtomicWriteFile')
            && pmssAtomicWriteFile($path, (string) $value);
    }
}

if (!function_exists('pmssIntegerSettingRuntimeUserPath')) {
    /** Resolve an integer-setting runtime bucket path for one user. */
    function pmssIntegerSettingRuntimeUserPath(string $bucket, string $username, ?string $runtimeDir = null): string { return rtrim(pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime'), '/').'/'.$bucket.'/'.$username; }
}

if (!function_exists('pmssIntegerSettingUserHomePath')) {
    /** Resolve an integer-setting file path under one user home. */
    function pmssIntegerSettingUserHomePath(string $username, string $filename, ?string $homeDir = null): string { return rtrim(pmssDirPathResolve($homeDir, 'PMSS_HOME_DIR', '/home'), '/').'/'.$username.'/'.ltrim($filename, '/'); }
}

if (!function_exists('pmssIntegerSettingPathModeConverge')) {
    function pmssIntegerSettingPathModeConverge(string $path, int $mode): bool
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

if (!function_exists('pmssIntegerSettingFileRemove')) {
    function pmssIntegerSettingFileRemove(string $path): bool
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return false;
        }

        file_exists($path) && @unlink($path);
        clearstatcache(true, $path);
        return !file_exists($path) && !is_link($path);
    }
}

if (!function_exists('pmssIntegerSettingStorageDirEnsure')) {
    function pmssIntegerSettingStorageDirEnsure(
        string $path,
        int $mode = 0700,
        string $owner = 'root',
        string $group = 'root'
    ): bool {
        if (!function_exists('pmssPathTargetIsSafe') || !pmssPathTargetIsSafe($path, true)) {
            return false;
        }

        if (function_exists('pmssEnsureDir')) {
            return pmssEnsureDir($path, $mode, $owner, $group) && is_dir($path) && !is_link($path);
        }

        if (!pmssDirEnsureExists($path, 0755) || !is_dir($path) || is_link($path)) {
            return false;
        }

        return pmssIntegerSettingPathModeConverge($path, $mode);
    }
}

if (!function_exists('pmssIntegerSettingTargetModesPersist')) {
    /** @param array<string,int> $targetModes */
    function pmssIntegerSettingTargetModesPersist(
        array $targetModes,
        int $value,
        ?string &$error = null,
        string $invalidValueError = 'invalid value',
        bool $removeZeroValue = false
    ): bool {
        $error = null;
        if ($value < 0) {
            $error = $invalidValueError;
            return false;
        }

        foreach ($targetModes as $target => $mode) {
            if ($removeZeroValue && $value === 0) {
                if (!file_exists($target) || pmssIntegerSettingFileRemove($target)) {
                    continue;
                }
                $error = 'refusing to remove non-file/symlink: '.$target;
                return false;
            }

            if (!pmssIntegerSettingFileWrite($target, $value)) {
                $error = 'failed to write '.$target;
                return false;
            }
            if (!pmssIntegerSettingPathModeConverge($target, (int) $mode)) {
                $error = 'failed to secure '.$target;
                return false;
            }
        }

        return true;
    }
}
