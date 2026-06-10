<?php
/**
 * Managed file writing helpers shared by lighttpd and other PMSS writers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../runtime/filesystem.php';

function pmssUserFilePathIsSafe(string $path): bool
{
    return pmssPathTargetIsSafe($path, false, true);
}

/**
 * Confirm a safe path resolves inside an already-existing directory root.
 */
function pmssPathWithinRootIsSafe(string $path, string $rootPath, bool $directoryTarget = false): bool
{
    $rootPath = rtrim($rootPath, '/');
    if ($rootPath === '') {
        $rootPath = '/';
    }

    if ($rootPath !== '/' && !pmssPathTargetIsSafe($rootPath, true)) {
        return false;
    }
    if (!pmssPathTargetIsSafe($path, $directoryTarget)) {
        return false;
    }

    $resolvedRoot = @realpath($rootPath);
    $resolvedPath = @realpath($path);
    if ($resolvedRoot === false || $resolvedPath === false) {
        return false;
    }
    if ($resolvedRoot === '/') {
        return $resolvedPath !== '' && $resolvedPath[0] === '/';
    }

    return $resolvedPath === $resolvedRoot || strpos($resolvedPath, $resolvedRoot.'/') === 0;
}

/**
 * Ensure a directory exists when the target path is safe.
 */
function pmssEnsureSafeDir(string $path, int $mode): bool
{
    if (!pmssPathTargetIsSafe($path, true)) {
        return false;
    }

    if (!pmssDirEnsureExists($path, $mode)) {
        return false;
    }

    @chmod($path, $mode);

    return is_dir($path) && !is_link($path);
}

/** Ensure each managed directory exists, reporting unsafe paths through the callback. */
function pmssManagedDirsEnsure(array $directories, callable $failureLogger): void { foreach ($directories as $dir => $mode) { pmssEnsureSafeDir((string) $dir, (int) $mode) || $failureLogger((string) $dir); } }

/** Apply owner/group metadata only when the caller is root. */
function pmssUserFileApplyOwnership(string $path, string $owner, ?string $group = null): void
{
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($path, $owner);
        @chgrp($path, ($group === null || $group === '') ? $owner : $group);
    }
}

/** Apply file mode plus root-only ownership metadata to a managed user path. */
function pmssUserFileApplyMetadata(string $path, string $owner, int $mode, ?string $group = null): void
{
    @chmod($path, $mode);
    pmssUserFileApplyOwnership($path, $owner, $group);
}

/**
 * Atomically replace a regular file, with optional temp-file preparation.
 */
function pmssReplaceUserFile(string $path, string $content, ?callable $prepareTemp = null): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    $tmp = @tempnam(dirname($path), basename($path).'.pmss-tmp-');
    if ($tmp === false || $tmp === '' || is_link($tmp) || !is_file($tmp) || @file_put_contents($tmp, $content) === false) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        return false;
    }

    if ($prepareTemp !== null) {
        try {
            $prepareTemp($tmp);
        } catch (\Throwable $exception) {
            @unlink($tmp);
            throw $exception;
        }
    }

    if (is_link($tmp) || !is_file($tmp)) {
        @unlink($tmp);
        return false;
    }

    if (!pmssUserFilePathIsSafe($path)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function pmssReplaceUserFileWithMetadata(string $path, string $content, int $mode, ?string $owner = null, ?string $group = null): bool
{
    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($mode, $owner, $group): void {
        if ($owner === null) {
            @chmod($tmpPath, $mode);
            return;
        }

        pmssUserFileApplyMetadata($tmpPath, $owner, $mode, $group);
    });
}

/** Replace a file while keeping existing mode/uid/gid when available. */
function pmssReplaceUserFilePreservingMetadata(string $path, string $content, int $fallbackMode = 0644): bool
{
    $mode = $fallbackMode;
    $owner = null;
    $group = null;
    $canAdjustOwnership = function_exists('posix_geteuid') && @posix_geteuid() === 0;

    if (is_file($path) && is_array($stat = @stat($path))) {
        $mode = $stat['mode'] & 0777;
        if ($canAdjustOwnership) {
            $owner = $stat['uid'];
            $group = $stat['gid'];
        }
    }

    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($group, $mode, $owner): void {
        @chmod($tmpPath, $mode);
        if ($owner !== null) @chown($tmpPath, $owner);
        if ($group !== null) @chgrp($tmpPath, $group);
    });
}

/** Persist a numeric network port through the shared symlink-safe writer. */
function pmssNetworkPortFileWrite(string $path, int $port, int $min = 1, int $max = 65535, int $fallbackMode = 0640): bool
{
    return pmssNetworkPortInRange($port, $min, $max)
        && pmssReplaceUserFilePreservingMetadata($path, (string) $port, $fallbackMode);
}

function pmssAtomicWriteFile(string $path, string $content, ?int $mode = null): bool
{
    return $mode === null ? pmssReplaceUserFile($path, $content) : pmssReplaceUserFileWithMetadata($path, $content, $mode);
}

function pmssWriteManagedFile(string $path, string $content, string $owner, ?string $group, int $mode): bool
{
    return pmssReplaceUserFileWithMetadata($path, $content, $mode, $owner, $group);
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    return pmssWriteManagedFile($path, $content, $owner, $owner, $mode);
}

/** Best-effort immutable toggle for managed root-owned files. */
function pmssManagedFileImmutableSet(string $path, bool $enable): void
{
    if (!is_file($path) || is_link($path)) {
        return;
    }

    static $chattr = null;
    if ($chattr === null) {
        $chattr = '';
        foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
            if (is_executable($candidate)) {
                $chattr = $candidate;
                break;
            }
        }
    }

    $chattr === '' || @exec($chattr.' '.($enable ? '+i' : '-i').' '.escapeshellarg($path).' 2>/dev/null');
}

/** Write one serialized payload to each managed target while preserving partial success. */
function pmssManagedSerializedTargetsWrite(string $serialized, array $targets, callable $failureLogger): bool
{
    $allWritesSucceeded = true;
    foreach ($targets as list($path, $group, $mode, $immutable)) {
        (bool) $immutable && pmssManagedFileImmutableSet((string) $path, false);
        try {
            $ok = pmssWriteManagedFile((string) $path, $serialized, 'root', (string) $group, (int) $mode);
        } finally {
            (bool) $immutable && pmssManagedFileImmutableSet((string) $path, true);
        }
        if (!$ok) {
            $allWritesSucceeded = false;
            $failureLogger((string) $path);
        }
    }
    return $allWritesSucceeded;
}

/**
 * Append content to a regular user-owned file when the target path is safe.
 *
 * This keeps legacy append workflows on the same path validation rules as the
 * atomic writer so symlinks and non-regular targets are rejected consistently.
 */
function pmssAppendUserFile(string $path, string $content, string $owner, int $mode): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    if (@file_put_contents($path, $content, FILE_APPEND | LOCK_EX) === false) {
        return false;
    }

    pmssUserFileApplyMetadata($path, $owner, $mode);

    return true;
}
