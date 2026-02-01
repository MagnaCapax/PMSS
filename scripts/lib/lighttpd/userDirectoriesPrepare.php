<?php
/**
 * User directory preparation helpers for per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssShouldConfigureLighttpdForHome(string $homeDir): bool
{
    if (!is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }
    if (is_dir($homeDir.'/www-disabled') || !is_dir($homeDir.'/www')) {
        return false;
    }
    return file_exists($homeDir.'/.rtorrent.rc');
}

function pmssPrepareLighttpdUserDirectories(string $user, string $homeDir, bool $deflateEnabled): bool
{
    if (!pmssValidateUsername($user)) {
        return false;
    }
    if (!is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    $directories = [
        '.lighttpd'          => 0751,
        '.lighttpd/custom.d' => 0750,
        '.lighttpd/upload'   => 0751,
    ];
    if ($deflateEnabled) {
        $directories['.lighttpd/compress'] = 0751;
    }
    $directories['www/public'] = 0751;
    foreach ($directories as $directory => $mode) {
        if (!pmssEnsureUserHomeDir($user, $homeDir, $directory, $mode)) {
            return false;
        }
    }

    // Ensure the optional user-controlled include exists so lighttpd start doesn't fail.
    $customFile = $homeDir.'/.lighttpd/custom';
    if (is_link($customFile) || (file_exists($customFile) && !is_file($customFile))) {
        return false;
    }
    if (!file_exists($customFile) && !pmssWriteUserFile($customFile, '', $user, 0751)) {
        return false;
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
