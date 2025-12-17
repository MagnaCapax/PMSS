<?php
/** Context + skeleton helpers for per-user update routines. */

require_once __DIR__.'/../../runtime.php';

function pmssUserSkelPath(string $relative): string { return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/'.$relative; }

/** Shell-ready skel arg; leaves default `/etc/skel/...` unquoted for legacy command stability. */
function pmssUserSkelCommandArg(string $relative): string
{
    $path = pmssUserSkelPath($relative);
    return $path === '/etc/skel/'.$relative ? $path : escapeshellarg($path);
}

/** Build per-user context for update-step2; returns null when home/tenant missing or user is suspended. */
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
