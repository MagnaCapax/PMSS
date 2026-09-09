<?php
/**
 * @domain user context — active tenant context
 *
 * Shared per-user context builders for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
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
 * @param string|null $reason       Short predicate slug explaining a null result.
 */
function pmssBuildUserContext(string $user, string $rutorrentIndexSha = '', ?string &$reason = null): ?array
{
    $reason = null;
    // Allow tests and development tooling to override the home root while
    // keeping the default `/home` behaviour for production.
    $home = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')."/{$user}";
    // The shared context only exists for active PMSS tenants; suspended users
    // are intentionally skipped to avoid recreating web roots or restarting
    // services mid-suspension.
    if (!is_dir($home)) {
        $reason = 'home-missing';
        return null;
    }
    if (!file_exists($home.'/.rtorrent.rc')) {
        $reason = 'rtorrent-rc-missing';
        return null;
    }
    if (!file_exists($home.'/data')) {
        $reason = 'data-dir-missing';
        return null;
    }
    if (is_dir("{$home}/www-disabled")) {
        $reason = 'suspended';
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

/**
 * Build the minimal context needed before active-tenant gating.
 *
 * Web-root convergence must run even when a damaged account is missing core
 * rtorrent state, but suspended accounts must remain untouched.
 *
 * @param string|null $reason Short predicate slug explaining a null result.
 */
function pmssBuildUserWebRootContext(string $user, string $rutorrentIndexSha = '', ?string &$reason = null): ?array
{
    $reason = null;
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $home = rtrim($homeRoot, '/')."/{$user}";
    if (!pmssValidateUsername($user)) {
        $reason = 'invalid-username';
        return null;
    }
    if (is_link($home)) {
        $reason = 'home-symlink';
        return null;
    }
    if (!is_dir($home)) {
        $reason = 'home-missing';
        return null;
    }
    if (!pmssPathWithinResolvedRoot($home, $homeRoot)) {
        $reason = 'home-outside-root';
        return null;
    }
    if (is_dir($home.'/www-disabled')) {
        $reason = 'suspended';
        return null;
    }

    return [
        'user'                => $user,
        'home'                => $home,
        'user_esc'            => escapeshellarg($user),
        'rutorrent_index_sha' => $rutorrentIndexSha,
    ];
}
