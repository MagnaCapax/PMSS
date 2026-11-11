<?php
/**
 * Shared helpers for user-facing integer settings.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/directories.php';

if (is_file(dirname(__DIR__).'/lighttpd/userFileWrite.php')) {
    require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';
}

require_once __DIR__.'/integerSettingValue.php';
require_once __DIR__.'/integerSettingRead.php';

function pmssIntegerSettingFileWrite(string $path, int $value): bool
{
    if ($value < 0 || !function_exists('pmssAtomicWriteFile')) return false;
    if (pmssManagedAccountHomePath($path)) {
        // New operator settings keep their historical root ownership.
        if (@lstat($path) === false) {
            return pmssRootStageFileWrite($path, (string) $value, 'root', 0644);
        }
        return pmssReplaceUserFilePreservingMetadata($path, (string) $value, 0600);
    }
    return pmssAtomicWriteFile($path, (string) $value);
}

/** Resolve an integer-setting runtime bucket path for one user. */
function pmssIntegerSettingRuntimeUserPath(string $bucket, string $username, ?string $runtimeDir = null): string { return rtrim(pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime'), '/').'/'.$bucket.'/'.$username; }

/** Resolve a managed file path under one user home. */
function pmssUserHomeFilePath(string $username, string $filename, ?string $homeDir = null): string { return rtrim(pmssDirPathResolve($homeDir, 'PMSS_HOME_DIR', '/home'), '/').'/'.$username.'/'.ltrim($filename, '/'); }

function pmssIntegerSettingPathModeConverge(string $path, int $mode): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        if ($context === null || $mode < 0 || $mode > 0777) return false;
        $entry = @lstat($path);
        if (!pmssUserFileStatIsSingleRegular($entry)) return false;
        if ($entry['uid'] === 0) {
            $group = @posix_getgrgid($entry['gid']);
            $content = pmssReadRegularFileContents($path);
            return is_array($group) && is_string($content)
                && pmssRootStageFileWrite($path, $content, $group['name'], $mode);
        }
        $account = @posix_getpwnam($context[2]);
        if (!is_array($account) || $entry['uid'] !== $account['uid']) return false;
        return pmssAccountPathRun($context[2], $context[1], [$path],
            'test ! -L '.escapeshellarg($path).' && chmod '.sprintf('%o', $mode).' -- '.escapeshellarg($path));
    }
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

function pmssIntegerSettingFileRemove(string $path): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        return $context !== null && pmssAccountPathRun($context[2], $context[1],
            [$context[1].'/.pmss-delete-check'], 'rm -f -- '.escapeshellarg($path));
    }
    if (!pmssLockFilePathIsSafe($path)) {
        return false;
    }

    file_exists($path) && @unlink($path);
    clearstatcache(true, $path);
    return !pmssPathExistsOrLink($path);
}

function pmssIntegerSettingStorageDirEnsure(
    string $path,
    int $mode = 0700,
    string $owner = 'root',
    string $group = 'root'
): bool {
    if (!pmssPathTargetIsSafe($path, true)) {
        return false;
    }

    return pmssEnsureDir($path, $mode, $owner, $group) && is_dir($path) && !is_link($path);
}

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
