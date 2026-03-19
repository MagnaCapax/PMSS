<?php
/**
 * Context and skeleton helpers for per-user update routines.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../runtime.php';

/**
 * Build the shared per-user context array used by update-step2 user helpers.
 *
 * Returns null when:
 * - The user home is missing
 * - Core rtorrent state is missing (not a PMSS tenant)
 * - The user appears suspended (canonical marker: `www-disabled` directory)
 *
 * @param string $user             Username (validated by callers).
 * @param string $rutorrentIndexSha Current ruTorrent index.html checksum.
 */
function pmssBuildUserContext(string $user, string $rutorrentIndexSha = ''): ?array
{
    // Allow tests and development tooling to override the home root while
    // keeping the default `/home` behaviour for production.
    $home = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')."/{$user}";
    // The shared context only exists for active PMSS tenants; suspended users
    // are intentionally skipped to avoid recreating web roots or restarting
    // services mid-suspension.
    if (!is_dir($home)
        || !file_exists($home.'/.rtorrent.rc')
        || !file_exists($home.'/data')
        || is_dir("{$home}/www-disabled")) {
        return null;
    }

    // rutorrent_index_sha tracks the current skeleton ruTorrent index.html
    // hash so callers can detect when per-user instances are out of date.
    return [
        'user'               => $user,
        'home'               => $home,
        'user_esc'           => escapeshellarg($user),
        'rutorrent_index_sha'=> $rutorrentIndexSha,
    ];
}
