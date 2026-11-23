<?php
/** A config writer must wait for every same-UID qBittorrent, including user services. */

/** Whether the process in $dir mounts the account's config dir: same device, mount root covering the path (unreadable counts). */
function pmssQbittorrentAccountMountsConfig(string $dir, string $config): bool
{
    $table = @file($dir.'/mountinfo', FILE_IGNORE_NEW_LINES);
    $real = @realpath($config);
    $stat = $real === false ? false : @stat($real);
    if ($table === false || $stat === false) return true;
    $dev = $stat['dev'];
    $id = (($dev >> 8) & 0xfff | (($dev >> 32) & ~0xfff)).':'.(($dev & 0xff) | (($dev >> 12) & ~0xff));
    $parts = explode('/', trim($real, '/'));
    foreach ($table as $line) {
        $field = explode(' ', $line);
        if (($field[2] ?? '') !== $id) continue;
        $root = stripcslashes($field[3] ?? '');
        // A mount root is relative to its filesystem, so every trailing part of the config path is a candidate.
        foreach (array_keys($parts) as $i) {
            $tail = '/'.implode('/', array_slice($parts, $i));
            if ($root === '/' || $tail === $root || strpos($tail, $root.'/') === 0 || strpos($root, $tail.'/') === 0) return true;
        }
    }
    return false;
}

/**
 * Name and UID guard writes. A same-UID process in another mount namespace (a container) counts only when it
 * mounts the account's config dir; without a readable own namespace under $proc every same-UID process counts.
 */
function pmssQbittorrentAccountPids(string $proc = '/proc', ?int $uid = null, ?string $config = null): array
{
    $uid = $uid ?? posix_geteuid();
    $pids = [];
    $ownNs = @readlink(rtrim($proc, '/').'/self/ns/mnt');
    $account = @posix_getpwuid($uid);
    $config = $config ?? (is_array($account) ? $account['dir'].'/.config/qBittorrent' : '');
    foreach (glob(rtrim($proc, '/').'/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (trim((string) @file_get_contents($dir.'/comm')) !== 'qbittorrent-nox') continue;
        // A build probe (qbittorrent-nox --version) runs in its own private profile; it is not this account's daemon.
        $argv = explode("\0", rtrim((string) @file_get_contents($dir.'/cmdline'), "\0"));
        if (array_slice($argv, 1) === ['--version']) continue;
        $status = @file_get_contents($dir.'/status');
        if (!is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $match) !== 1 || (int) $match[1] !== $uid) continue;
        $ns = @readlink($dir.'/ns/mnt');
        if ($ownNs !== false && $ns !== false && $ns !== $ownNs && $config !== '' && !pmssQbittorrentAccountMountsConfig($dir, $config)) continue;
        $pids[] = (int) basename($dir);
    }
    return $pids;
}
