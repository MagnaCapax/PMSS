<?php
/**
 * Context and skeleton helpers for per-user update routines.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../runtime.php';

function pmssUserSkelPath(string $relative): string { return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/'.$relative; }

/**
 * Return a shell-ready argument for a skel path.
 *
 * Keep legacy command strings stable: when PMSS_SKEL_DIR is the default
 * `/etc/skel`, older scripts historically passed the path unquoted in the
 * generated `cp` command. When overridden, we must escape the custom path.
 */
function pmssUserSkelCommandArg(string $relative): string
{
    $path = pmssUserSkelPath($relative);
    return $path === '/etc/skel/'.$relative ? $path : escapeshellarg($path);
}

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
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');

    $home = "{$homeRoot}/{$user}";
    if (!is_dir($home)) {
        return null;
    }
    foreach (['.rtorrent.rc', 'data'] as $required) {
        if (!file_exists("{$home}/{$required}")) {
            return null;
        }
    }
    if (is_dir("{$home}/www-disabled")) {
        // Suspended users are intentionally skipped during updates to avoid
        // recreating web roots or restarting services mid-suspension.
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
