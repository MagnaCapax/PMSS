<?php
/**
 * Managed file writing helpers shared by lighttpd and other PMSS writers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';

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

    if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
        return false;
    }

    @chmod($path, $mode);

    return is_dir($path) && !is_link($path);
}

function pmssUserFileApplyMetadata(string $path, string $owner, int $mode, ?string $group = null): void
{
    @chmod($path, $mode);
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($path, $owner);
        @chgrp($path, ($group === null || $group === '') ? $owner : $group);
    }
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

function pmssAtomicWriteFile(string $path, string $content, ?int $mode = null): bool
{
    return $mode === null ? pmssReplaceUserFile($path, $content) : pmssReplaceUserFileWithMetadata($path, $content, $mode);
}

function pmssJsonFileReadAssoc(string $path, bool $safePathRequired = false): ?array
{
    if ($path === '' || ($safePathRequired && !pmssUserFilePathIsSafe($path)) || !is_file($path) || is_link($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return is_array($decoded = json_decode($raw, true)) ? $decoded : null;
}

function pmssWriteManagedFile(string $path, string $content, string $owner, ?string $group, int $mode): bool
{
    return pmssReplaceUserFileWithMetadata($path, $content, $mode, $owner, $group);
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    return pmssWriteManagedFile($path, $content, $owner, $owner, $mode);
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
