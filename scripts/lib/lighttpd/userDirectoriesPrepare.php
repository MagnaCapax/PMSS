<?php
/**
 * Per-user lighttpd directory, socket, and freshness helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/directories.php';
require_once __DIR__.'/userFileWrite.php';

function pmssShouldConfigureLighttpdForHome(string $homeDir): bool
{
    return is_dir($homeDir)
        && !is_link($homeDir)
        && !is_dir($homeDir.'/www-disabled')
        && is_dir($homeDir.'/www')
        && file_exists($homeDir.'/.rtorrent.rc');
}

function pmssLighttpdWatchdogSocketPaths(string $homeDir, string $configPath): array
{
    $baseSocketPath = rtrim($homeDir, '/').'/.lighttpd/php.socket';
    $config = (string) @file_get_contents($configPath);
    $maxProcs = preg_match('/"max-procs"\s*=>\s*([0-9]+)/', $config, $matches) === 1 ? (int) $matches[1] : 0;
    $minProcs = preg_match('/"min-procs"\s*=>\s*([0-9]+)/', $config, $matches) === 1 ? (int) $matches[1] : 0;

    if ($maxProcs === 1) {
        return [$baseSocketPath];
    }
    if ($maxProcs <= 0) {
        return [$baseSocketPath.'-0'];
    }

    return array_map(static function ($index) use ($baseSocketPath) {
        return $baseSocketPath.'-'.$index;
    }, range(0, min($maxProcs, $minProcs > 0 ? $minProcs : $maxProcs) - 1));
}

function pmssLighttpdWatchedConfigPaths(string $homeDir, string $configPath): array
{
    $paths = [$configPath, rtrim($homeDir, '/').'/.lighttpd/custom'];
    foreach (glob(rtrim($homeDir, '/').'/.lighttpd/custom.d/*.conf') ?: [] as $path) {
        $paths[] = $path;
    }

    return array_values(array_filter($paths, static function (string $path): bool {
        return is_file($path) && !is_link($path);
    }));
}

function pmssLighttpdNewestConfigMtime(string $homeDir, string $configPath): ?int
{
    $newest = null;
    foreach (pmssLighttpdWatchedConfigPaths($homeDir, $configPath) as $path) {
        $mtime = @filemtime($path);
        if (is_int($mtime)) {
            $newest = $newest === null ? $mtime : max($newest, $mtime);
        }
    }

    return $newest;
}

function pmssLighttpdConfigNewerThanProcess(string $homeDir, string $configPath, ?int $processStartTime): bool
{
    if ($processStartTime === null) {
        return false;
    }

    $configMtime = pmssLighttpdNewestConfigMtime($homeDir, $configPath);
    return $configMtime !== null && $configMtime > $processStartTime;
}

function pmssPrepareLighttpdUserDirectories(string $user, string $homeDir, bool $deflateEnabled): bool
{
    if (!pmssValidateUsername($user) || !is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    $directories = ['.lighttpd' => 0751, '.lighttpd/custom.d' => 0750, '.lighttpd/upload' => 0751, 'www/public' => 0751];
    if ($deflateEnabled) {
        $directories['.lighttpd/compress'] = 0751;
    }
    foreach ($directories as $directory => $mode) {
        if (!pmssEnsureUserHomeDir($user, $homeDir, $directory, $mode)) {
            return false;
        }
    }

    $customFile = $homeDir.'/.lighttpd/custom';
    if (is_link($customFile) || (file_exists($customFile) && !is_file($customFile))) {
        return false;
    }

    return file_exists($customFile) || pmssWriteUserFile($customFile, '', $user, 0751);
}

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
