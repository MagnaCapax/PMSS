<?php
/**
 * Managed file writing helpers shared by lighttpd and other PMSS writers.
 *
 * @license GPL-3.0-only
 */

function pmssUserFilePathIsSafe(string $path): bool
{
    if (strpos($path, "\0") !== false || is_link($path) || (file_exists($path) && !is_file($path))) {
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }

    return true;
}

function pmssUserFileApplyMetadata(string $path, string $owner, int $mode): void
{
    @chmod($path, $mode);
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($path, $owner);
        @chgrp($path, $owner);
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
        $prepareTemp($tmp);
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function pmssAtomicWriteFile(string $path, string $content): bool
{
    return pmssReplaceUserFile($path, $content);
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    return pmssReplaceUserFile($path, $content, static function (string $tmp) use ($owner, $mode): void {
        pmssUserFileApplyMetadata($tmp, $owner, $mode);
    });
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
