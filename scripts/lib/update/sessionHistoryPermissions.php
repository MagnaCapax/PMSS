<?php
/** Keep legacy login records and Debian 13 databases on the same permission policy. */

/**
 * Remove only other-read from existing session records and related binaries.
 * The root argument keeps development tests away from host paths.
 */
function pmssSessionHistoryPermissionsHarden(string $root = ''): bool
{
    if (getenv('PMSS_DRY_RUN') === '1') {
        return true;
    }

    $root = rtrim($root, '/');
    $paths = [
        '/var/log/wtmp', '/var/run/utmp', '/var/log/lastlog', '/var/log/faillog',
        '/usr/bin/netstat', '/usr/bin/who', '/usr/bin/w',
        '/var/log/wtmp.db', '/var/lib/lastlog/lastlog2.db',
    ];
    foreach (glob($root.'/var/log/wtmp_*.db') ?: [] as $path) {
        $paths[] = substr($path, strlen($root));
    }

    foreach ($paths as $path) {
        $target = $root.$path;
        clearstatcache(true, $target);
        $stats = @lstat($target);
        if ($stats === false) {
            continue;
        }
        // Only harden the named record or binary, never a redirected target or directory.
        $mode = $stats['mode'];
        if (($mode & 0170000) !== 0100000 || (($mode & 0004) !== 0 && !@chmod($target, ($mode & 07777) & ~0004))) {
            throw new RuntimeException('Cannot harden permissions: '.$path);
        }
    }

    return true;
}
