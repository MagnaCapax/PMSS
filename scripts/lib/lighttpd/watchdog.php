<?php
/**
 * Helpers shared by the per-user lighttpd watchdog.
 *
 * @license GPL-3.0-only
 */

/**
 * Read the configured FastCGI worker count from a rendered lighttpd config.
 *
 * Returns null when the watchdog cannot determine the worker count and should
 * fall back to its legacy single-socket probe.
 */
function pmssLighttpdWatchdogConfigMaxProcs(string $configPath): ?int
{
    $config = @file_get_contents($configPath);
    if (!is_string($config) || $config === '' || !preg_match('/"max-procs"\s*=>\s*([0-9]+)/', $config, $matches)) {
        return null;
    }

    $maxProcs = (int) $matches[1];
    return $maxProcs > 0 ? $maxProcs : null;
}

/**
 * Build the FastCGI socket list the watchdog must probe for a user.
 *
 * PMSS historically probed only `php.socket-0`. Keep that as the fallback when
 * the rendered config is unavailable, while using the configured `max-procs`
 * value when it can be read safely.
 *
 * @return array<int, string>
 */
function pmssLighttpdWatchdogSocketPaths(string $homeDir, string $configPath): array
{
    $baseSocketPath = rtrim($homeDir, '/').'/.lighttpd/php.socket';
    $maxProcs = pmssLighttpdWatchdogConfigMaxProcs($configPath);

    if ($maxProcs === null) {
        return [$baseSocketPath.'-0'];
    }

    if ($maxProcs === 1) {
        return [$baseSocketPath];
    }

    return array_map(static function ($index) use ($baseSocketPath) {
        return $baseSocketPath.'-'.$index;
    }, range(0, $maxProcs - 1));
}
