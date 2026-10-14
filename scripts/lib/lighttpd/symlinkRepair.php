<?php
/**
 * Narrow repair of account-owned lighttpd paths that were left as symlinks.
 * The symlink target is never read, written, or removed.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/accountPath.php';
require_once __DIR__.'/watchdogErrorPageProbe.php';

/** Require a successful live quota probe; a stale snapshot cannot authorize writes. */
function pmssLighttpdSymlinkRepairQuotaAllows(string $username): bool
{
    $result = pmssCommandCapture(pmssBuildCommand('quota', ['-u', $username, '-s']));
    if ($result['rc'] !== 0) return false;
    $state = pmssLighttpdWatchdogQuotaStateParse($result['stdout']);
    return $state !== null
        && !pmssLighttpdWatchdogQuotaOutputShowsExhaustion($result['stdout'])
        && $state['hardLimitBytes'] > 0
        && $state['usedBytes'] < $state['hardLimitBytes'];
}

/** Replace one symlink with an empty account-owned directory or file. */
function pmssLighttpdSymlinkRepairOne(string $username, string $home, string $name, bool $directory): bool
{
    if (!in_array($name, ['upload', 'access.log', 'error.log'], true)
        || ($name === 'upload') !== $directory
        || !pmssPathTargetIsSafe($home, true)
        || !is_dir($home)) return false;

    $parent = $home.'/.lighttpd';
    $path = $parent.'/'.$name;
    if (!pmssPathTargetIsSafe($parent, true, true) || !is_dir($parent)
        || !pmssPathWithinResolvedRoot($path, $home)) return false;
    clearstatcache(true, $path);
    if (!is_link($path)) return !file_exists($path) || ($directory ? is_dir($path) : is_file($path));

    pmssUserLifecycleContextLog('lighttpd_symlink_repair', 'before', $username,
        ['path' => $path, 'status' => 'symlink']);
    // The account identity limits a race in its writable parent to that account's rights.
    $quoted = escapeshellarg($path);
    $create = $directory ? 'mkdir -m 0751 -- '.$quoted : 'umask 027 && set -C && : > '.$quoted;
    $command = 'test -L '.$quoted.' && rm -- '.$quoted.' && '.$create;
    $ok = pmssAccountPathRun($username, $home, [$parent], $command);
    clearstatcache(true, $path);
    $ok = $ok && !is_link($path) && ($directory ? is_dir($path) : is_file($path));
    pmssUserLifecycleContextLog('lighttpd_symlink_repair', 'after', $username,
        ['path' => $path, 'status' => $ok ? 'repaired' : 'failed']);
    return $ok;
}
