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
    $removedCount = 0;
    foreach (glob(rtrim($lighttpdDir, '/').'/php.socket*') ?: [] as $socketPath) {
        $removedCount += (int) @unlink($socketPath);
    }

    return $removedCount;
}
