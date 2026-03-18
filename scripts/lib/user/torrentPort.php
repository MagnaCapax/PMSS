<?php
/**
 * Per-user torrent client port helpers for web frontends.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lighttpd/userConfigApply.php';

/**
 * Resolve the current user and home directory for frontend calls.
 *
 * @return array<string, string>|null
 */
function pmssTorrentPortCurrentUserContext(): ?array
{
    $home = getenv('HOME');
    if (!is_string($home) || $home === '') {
        return null;
    }

    $user = basename($home);
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid(@posix_geteuid());
        if (is_array($info) && isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
            $user = $info['name'];
        }
    }

    return $user === '' ? null : ['user' => $user, 'home' => $home];
}

/**
 * Read an expected port file and reject out-of-range values.
 */
function pmssTorrentPortExpectedRead(string $path): ?int
{
    $raw = trim((string) @file_get_contents($path));
    if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) {
        return null;
    }

    $port = (int) $raw;
    return ($port >= 1024 && $port <= 65535) ? $port : null;
}

/**
 * Best-effort kill of a per-user torrent process before rewriting its port.
 */
function pmssTorrentPortProcessKill(string $user, string $process): void
{
    @shell_exec(sprintf('killall -u %s -9 %s 2>/dev/null', escapeshellarg($user), escapeshellarg($process)));
}

/**
 * Atomically rewrite a user-owned config file while preserving the mode.
 */
function pmssTorrentPortFileWrite(string $path, string $content, string $owner): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }

    $mode = @fileperms($path);
    $tmp = @tempnam($dir, basename($path).'.pmss-tmp-');
    if ($tmp === false) {
        return false;
    }
    if (@file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, is_int($mode) ? ($mode & 0777) : 0600);
    @chown($tmp, $owner);
    @chgrp($tmp, $owner);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Ensure the current user's Deluge web port matches ~/.delugePort.
 */
function pmssDelugePortEnsureCurrentUser(): bool
{
    $ctx = pmssTorrentPortCurrentUserContext();
    return is_array($ctx) ? pmssDelugePortEnsure($ctx['user'], $ctx['home']) : false;
}

/**
 * Ensure a user's Deluge web.conf uses the expected port.
 */
function pmssDelugePortEnsure(string $user, string $home): bool
{
    $expectedPort = pmssTorrentPortExpectedRead($home.'/.delugePort');
    $parsed = pmssDelugeReadWebConf($home.'/.config/deluge/web.conf');
    $port = is_array($parsed) ? ($parsed['config']['port'] ?? null) : null;
    if ($expectedPort === null || !is_array($parsed) || !is_int($port)) {
        return false;
    }
    if ($port === $expectedPort) {
        return true;
    }

    pmssTorrentPortProcessKill($user, 'deluge-web');
    $parsed['config']['port'] = $expectedPort;
    pmssDelugeNormalizeEmptySessionsObject($parsed['config']);
    return pmssDelugeWriteWebConf($home.'/.config/deluge/web.conf', $parsed['meta'], $parsed['config'], $user);
}

/**
 * Ensure the current user's qBittorrent WebUI port matches ~/.qbittorrentPort.
 */
function pmssQbittorrentPortEnsureCurrentUser(): bool
{
    $ctx = pmssTorrentPortCurrentUserContext();
    return is_array($ctx) ? pmssQbittorrentPortEnsure($ctx['user'], $ctx['home']) : false;
}

/**
 * Ensure a user's qBittorrent.conf uses the expected WebUI port.
 */
function pmssQbittorrentPortEnsure(string $user, string $home): bool
{
    $expectedPort = pmssTorrentPortExpectedRead($home.'/.qbittorrentPort');
    $configPath = $home.'/.config/qBittorrent/qBittorrent.conf';
    $config = @file_get_contents($configPath);
    $parsed = is_string($config) ? @parse_ini_string($config, true, INI_SCANNER_RAW) : false;
    $key = 'WebUI\\Port';
    $currentPort = is_array($parsed) && isset($parsed['Preferences'][$key]) ? (string) $parsed['Preferences'][$key] : '';
    if ($expectedPort === null || !is_string($config) || $currentPort === '') {
        return false;
    }
    if ($currentPort === (string) $expectedPort) {
        return true;
    }

    $updated = preg_replace('/^WebUI\\\\Port=.*$/m', 'WebUI\\\\Port='.$expectedPort, $config, 1, $count);
    if (!is_string($updated) || $count !== 1) {
        return false;
    }

    pmssTorrentPortProcessKill($user, 'qbittorrent-nox');
    return pmssTorrentPortFileWrite($configPath, $updated, $user);
}
