<?php
/**
 * Helpers shared by per-user lighttpd watchdog flows.
 *
 * @license GPL-3.0-only
 */

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
    $maxProcs = null;
    if (preg_match('/"max-procs"\s*=>\s*([0-9]+)/', (string) @file_get_contents($configPath), $matches) === 1) {
        $maxProcs = ((int) $matches[1]) ?: null;
    }

    return $maxProcs > 1
        ? array_map(static function ($index) use ($baseSocketPath) { return $baseSocketPath.'-'.$index; }, range(0, $maxProcs - 1))
        : [$baseSocketPath.($maxProcs === 1 ? '' : '-0')];
}
