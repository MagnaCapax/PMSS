<?php
/**
 * User directory preparation helpers for per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssShouldConfigureLighttpdForHome(string $homeDir): bool
{
    if (!is_dir($homeDir)) {
        return false;
    }
    if (is_link($homeDir)) {
        return false;
    }
    if (is_dir($homeDir.'/www-disabled') || !is_dir($homeDir.'/www')) {
        return false;
    }
    if (!file_exists($homeDir.'/.rtorrent.rc')) {
        return false;
    }
    return true;
}

function pmssPrepareLighttpdUserDirectories(string $user, string $homeDir, bool $deflateEnabled): bool
{
    if (!pmssValidateUsername($user)) {
        return false;
    }
    if (!is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    if (!pmssEnsureUserHomeDir($user, $homeDir, '.lighttpd', 0751)) {
        return false;
    }
    if (!pmssEnsureUserHomeDir($user, $homeDir, '.lighttpd/custom.d', 0750)) {
        return false;
    }
    if (!pmssEnsureUserHomeDir($user, $homeDir, '.lighttpd/upload', 0751)) {
        return false;
    }
    if ($deflateEnabled) {
        if (!pmssEnsureUserHomeDir($user, $homeDir, '.lighttpd/compress', 0751)) {
            return false;
        }
    }
    if (!pmssEnsureUserHomeDir($user, $homeDir, 'www/public', 0751)) {
        return false;
    }

    // Ensure the optional user-controlled include exists so lighttpd start doesn't fail.
    $customFile = $homeDir.'/.lighttpd/custom';
    if (is_link($customFile)) {
        return false;
    }
    if (file_exists($customFile) && !is_file($customFile)) {
        return false;
    }
    if (!file_exists($customFile)) {
        if (!pmssWriteUserFile($customFile, '', $user, 0751)) {
            return false;
        }
    }

    return true;
}

/**
 * Ensure the WebDAV lock database is present and owned by the target user.
 */
function pmssEnsureWebdavLockDatabase(string $user, string $homeDir): void
{
    $lighttpdDir = $homeDir.'/.lighttpd';
    if (!is_dir($lighttpdDir) || is_link($lighttpdDir)) {
        return;
    }

    $lockFile = $lighttpdDir.'/webdav.lock.db';
    if (is_link($lockFile)) {
        return;
    }
    if (!file_exists($lockFile)) {
        @touch($lockFile);
        // Clear stat cache so subsequent checks see the new lock file.
        clearstatcache(true, $lockFile);
    }
    if (!is_file($lockFile)) {
        return;
    }
    @chmod($lockFile, 0600);
    clearstatcache(true, $lockFile);

    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($lighttpdDir, $user);
        @chgrp($lighttpdDir, $user);
        @chown($lockFile, $user);
        @chgrp($lockFile, $user);
    }
}
