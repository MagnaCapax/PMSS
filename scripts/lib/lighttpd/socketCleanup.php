<?php
/**
 * Per-user lighttpd socket cleanup helpers.
 *
 * @license GPL-3.0-only
 */

/**
 * Remove stale FastCGI socket entries before launching lighttpd.
 *
 * FastCGI endpoints are UNIX sockets, not regular files. Use unlink directly
 * for every matched path so stale socket nodes do not block restarts.
 */
function pmssLighttpdRemoveSocketEntries(string $lighttpdDir): int
{
    $socketPaths = glob(rtrim($lighttpdDir, '/').'/php.socket*');
    if ($socketPaths === false) {
        return 0;
    }

    $removedCount = 0;
    foreach ($socketPaths as $socketPath) {
        $removedCount += @unlink($socketPath) ? 1 : 0;
    }

    return $removedCount;
}
