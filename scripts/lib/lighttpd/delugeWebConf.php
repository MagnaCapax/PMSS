<?php
/**
 * Deluge web.conf handling for per-user lighttpd reverse proxying.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/delugeManagedConfig.php';
require_once __DIR__.'/userFileWrite.php';

function pmssDelugeSessionsListDetected(string $raw): bool
{
    return preg_match('/"sessions"\\s*:\\s*\\[\\s*\\]/', $raw) === 1;
}

function pmssDelugeNormalizeEmptySessionsObject(array &$config): bool
{
    if (!array_key_exists('sessions', $config) || !is_array($config['sessions']) || count($config['sessions']) !== 0) {
        return false;
    }

    $config['sessions'] = (object) [];

    return true;
}

function pmssDelugeReadWebConf(string $path): ?array
{
    $raw = @file_get_contents($path);

    return is_string($raw) ? pmssDelugeConfigDecode($raw) : null;
}

function pmssDelugeWriteWebConf(string $path, array $meta, array $config, string $owner): bool
{
    $mode = is_int($existingMode = @fileperms($path)) ? ($existingMode & 0777) : 0600;
    $encoded = pmssDelugeConfigEncode($meta, $config);

    return is_string($encoded) && pmssWriteUserFile($path, $encoded, $owner, $mode);
}

function pmssLighttpdDelugeWebPortFromConfig(string $user, string $path): ?int
{
    if (!is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    $needsWrite = is_string($raw) && pmssDelugeSessionsListDetected($raw);
    $parsed = pmssDelugeReadWebConf($path);
    if (!is_array($parsed) || !isset($parsed['config'], $parsed['meta']) || !is_array($parsed['config']) || !is_array($parsed['meta'])) {
        return null;
    }

    $port = $parsed['config']['port'] ?? null;
    $webPort = is_int($port) && $port >= 1024 && $port <= 65535 ? $port : null;
    $expectedBase = "/user-{$user}/deluge/";
    $base = $parsed['config']['base'] ?? null;
    if (is_string($base) && in_array($base, ["/user-{$user}/deluge", "/deluge-{$user}/", "/deluge-{$user}"], true)) {
        $parsed['config']['base'] = $expectedBase;
        $needsWrite = true;
    }
    if ($needsWrite) {
        pmssDelugeNormalizeEmptySessionsObject($parsed['config']);
        if (pmssDelugeWriteWebConf($path, $parsed['meta'], $parsed['config'], $user)) {
            passthru('killall -u '.escapeshellarg($user).' -TERM deluge-web 2>/dev/null || true');
        }
    }

    return $webPort;
}

function pmssLighttpdDelugeWebPortFallback(string $homeDir): ?int
{
    $delugePort = pmssReadRegularFileInt($homeDir.'/.delugePort');
    if ($delugePort < 1024 || $delugePort > 65535) {
        return null;
    }

    foreach ([$delugePort + 1, $delugePort] as $candidate) {
        if ($candidate < 1024 || $candidate > 65535) {
            continue;
        }
        $sock = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
        if (is_resource($sock)) {
            fclose($sock);
            return $candidate;
        }
    }

    $candidate = $delugePort + 1;

    return ($candidate >= 1024 && $candidate <= 65535) ? $candidate : $delugePort;
}

function pmssLighttpdDelugeWebPortResolve(string $user, string $homeDir): ?int
{
    return pmssLighttpdDelugeWebPortFromConfig($user, $homeDir.'/.config/deluge/web.conf')
        ?? pmssLighttpdDelugeWebPortFallback($homeDir);
}
