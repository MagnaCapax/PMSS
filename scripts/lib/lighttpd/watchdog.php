<?php
/**
 * Helpers shared by per-user lighttpd watchdog and startup flows.
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
    if (is_string($config = @file_get_contents($configPath))
        && preg_match('/"max-procs"\s*=>\s*([0-9]+)/', $config, $matches) === 1) {
        $maxProcs = ((int) $matches[1]) ?: null;
    }

    return $maxProcs > 1
        ? array_map(static function ($index) use ($baseSocketPath) { return $baseSocketPath.'-'.$index; }, range(0, $maxProcs - 1))
        : [$baseSocketPath.($maxProcs === 1 ? '' : '-0')];
}

/**
 * Remove stale FastCGI socket entries before launching lighttpd.
 *
 * FastCGI endpoints are UNIX sockets, not regular files. Use unlink directly
 * for every matched path so stale socket nodes do not block restarts.
 */
function pmssLighttpdRemoveSocketEntries(string $lighttpdDir): int
{
    $removedCount = 0;
    foreach (glob(rtrim($lighttpdDir, '/').'/php.socket*') ?: [] as $socketPath) {
        $removedCount += (int) @unlink($socketPath);
    }

    return $removedCount;
}
