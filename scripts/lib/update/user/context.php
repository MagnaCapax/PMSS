<?php
/**
 * Context builders for per-user update routines.
 */

function pmssBuildUserContext(string $user, string $rutorrentIndexSha = ''): ?array
{
    $home = "/home/{$user}";
    if (!is_dir($home)) {
        return null;
    }
    if (!file_exists("{$home}/.rtorrent.rc")) {
        return null;
    }
    if (!file_exists("{$home}/data")) {
        return null;
    }
    if (file_exists("{$home}/www-disabled")) {
        return null;
    }

    return [
        'user'               => $user,
        'home'               => $home,
        'user_esc'           => escapeshellarg($user),
        'rutorrent_index_sha'=> $rutorrentIndexSha,
    ];
}
