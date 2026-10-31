<?php
/** Inspect web archives before extraction and verify their filesystem result. */

/** List names and types separately; escaped unusual names fail the lexical check. */
function pmssTorrentClientsTransmissionWebArchiveList(string $archive): array
{
    // Keep malformed filenames on the same unavailable-listing path as tar failures.
    if ($archive === '' || pmssFilesystemPathHasNulByte($archive)) return [];
    $arg = escapeshellarg($archive);
    return @pmssPinnedRemoteArchiveMembersRead('LC_ALL=C tar --quoting-style=escape -tzf '.$arg.' 2>/dev/null', 'LC_ALL=C tar --quoting-style=escape -tvzf '.$arg.' 2>/dev/null');
}

/** Accept only unique regular files/directories strictly below public_html. */
function pmssTorrentClientsTransmissionWebArchiveSafe(array $members): bool
{
    if ($members === []) return false;
    $seen = [];
    foreach ($members as $member) {
        if (!is_array($member) || !is_string($member['path'] ?? null)
            || !in_array($member['type'] ?? '', ['-', 'd'], true)
            || preg_match('~\Apublic_html/(?:[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*/?)?\z~', $member['path']) !== 1) return false;
        $path = rtrim($member['path'], '/');
        if (!pmssPathRelativeStringIsSafe($path, ['allowEmptySegments' => true])) return false;
        if (isset($seen[$path]) || ($path === 'public_html' && $member['type'] !== 'd')
            || (substr($member['path'], -1) === '/' && $member['type'] !== 'd')) return false;
        $seen[$path] = true;
    }
    return true;
}

/** Reject links/special nodes before setting root ownership and public read modes. */
function pmssTorrentClientsTransmissionWebTreeNormalize(string $path): bool
{
    $stat = @lstat($path);
    if (!is_array($stat)) return false;
    $type = $stat['mode'] & 0170000;
    if (!in_array($type, [0040000, 0100000], true)
        || ($type === 0100000 && $stat['nlink'] !== 1)) return false;
    [$uid, $gid] = pmssTorrentClientsStoreOwnerIds();
    if (($stat['uid'] !== $uid && !@chown($path, $uid))
        || ($stat['gid'] !== $gid && !@chgrp($path, $gid))
        || !@chmod($path, $type === 0040000 ? 0755 : 0644)) return false;
    if ($type === 0100000) return true;
    $children = pmssDirectoryEntriesRead($path);
    if ($children === false) return false;
    foreach ($children as $child) {
        if (!pmssTorrentClientsTransmissionWebTreeNormalize($path.'/'.$child)) return false;
    }
    return true;
}

/** Remove only a caller-owned workspace or its saved old tree; never follow links. */
function pmssTorrentClientsTransmissionWebTreeRemove(string $path): bool
{
    if (!is_dir($path) || is_link($path)) return @unlink($path);
    $children = pmssDirectoryEntriesRead($path);
    if ($children === false) return false;
    $ok = true;
    foreach ($children as $child) {
        $ok = pmssTorrentClientsTransmissionWebTreeRemove($path.'/'.$child) && $ok;
    }
    return @rmdir($path) && $ok;
}
