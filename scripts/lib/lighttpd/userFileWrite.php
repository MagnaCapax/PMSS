<?php
/**
 * File writing helpers used by per-user lighttpd configuration.
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

function pmssAtomicWriteFile(string $path, string $content): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    $dir = dirname($path);
    $tmp = @tempnam($dir, basename($path).'.pmss-tmp-');
    if ($tmp === false) {
        return false;
    }

    if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    if (!pmssAtomicWriteFile($path, $content)) {
        return false;
    }
    pmssUserFileApplyMetadata($path, $owner, $mode);
    return true;
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
