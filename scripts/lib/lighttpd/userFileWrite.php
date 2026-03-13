<?php
/**
 * File writing helpers used by per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssAtomicWriteFile(string $path, string $content): bool
{
    if (strpos($path, "\0") !== false || is_link($path) || (file_exists($path) && !is_file($path))) {
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }

    $tmp = @tempnam($dir, basename($path).'.pmss-tmp-');
    if ($tmp === false) {
        return false;
    }

    if (@file_put_contents($tmp, $content) !== false && @rename($tmp, $path)) {
        return true;
    }

    @unlink($tmp);
    return false;
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    if (!pmssAtomicWriteFile($path, $content)) {
        return false;
    }
    @chmod($path, $mode);
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($path, $owner);
        @chgrp($path, $owner);
    }
    return true;
}
