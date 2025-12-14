<?php
/**
 * Context builders for per-user update routines.
 */

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
    $homeRoot = getenv('PMSS_HOME_DIR');
    if ($homeRoot === false || $homeRoot === '') {
        $homeRoot = '/home';
    }
    $homeRoot = rtrim($homeRoot, '/');
    if ($homeRoot === '') {
        $homeRoot = '/home';
    }

    $home = "{$homeRoot}/{$user}";
    if (!is_dir($home)) {
        return null;
    }
    if (!file_exists("{$home}/.rtorrent.rc")) {
        return null;
    }
    if (!file_exists("{$home}/data")) {
        return null;
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
