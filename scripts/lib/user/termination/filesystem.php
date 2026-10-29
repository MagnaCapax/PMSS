<?php
/** Guarded direct path removal and nginx route retirement.
 * Loaded by terminationCleanup.php; public entry points retain their contracts.
 * @license GPL-3.0-only
 */

/**
 * Prove that exact, same-filesystem leftovers have one departed account UID.
 * Missing paths are allowed; every existing sibling must pass before returning a UID.
 * @return array{uid:?int,reason:string}
 */
function pmssTerminateUserResumeUid(string $username, array $purgePaths): array
{
    $refuse = static function (string $reason): array { return array('uid' => null, 'reason' => $reason); };
    if (!pmssUsernameIsValid($username) || $purgePaths !== array(
        'home' => '/home/'.$username,
        'user_backup' => '/home/backup-'.$username,
        'user_backup_superseded' => '/home/backup-'.$username.'.superseded',
    )) return $refuse('unsafe_path');
    $home = @stat('/home');
    if ($home === false) return $refuse('lookup_failed');
    $uid = null;
    foreach ($purgePaths as $path) {
        clearstatcache(true, $path);
        $info = @lstat($path);
        if ($info === false) continue;
        $type = $info['mode'] & 0170000;
        if ($type === 0120000) return $refuse('symlink');
        if (realpath($path) !== $path) return $refuse('unsafe_path');
        if ($type !== 0040000) return $refuse('not_directory');
        if ($info['dev'] !== $home['dev']) return $refuse('not_same_fs');
        if ($info['uid'] < 1000) return $refuse('uid_out_of_range');
        if ($uid !== null && $uid !== $info['uid']) return $refuse('owner_mismatch');
        $uid = $info['uid'];
    }
    if ($uid === null) return $refuse('nothing_to_resume');
    // NSS failure is not evidence of absence. Require local passwd readability too.
    foreach (array('posix_getpwnam', 'posix_getpwuid', 'posix_get_last_error') as $function) {
        if (!function_exists($function)) return $refuse('lookup_failed');
    }
    if (!is_readable('/etc/passwd') || @file_get_contents('/etc/passwd') === false) return $refuse('lookup_failed');
    try {
        $account = posix_getpwnam($username);
        if ($account !== false || !in_array(posix_get_last_error(), array(0, 2), true)) return $refuse('lookup_failed');
        $owner = posix_getpwuid($uid);
        if (is_array($owner) && isset($owner['name'], $owner['uid']) && (int) $owner['uid'] === $uid) {
            return $refuse('uid_reassigned:'.$owner['name']);
        }
        if ($owner !== false || !in_array(posix_get_last_error(), array(0, 2), true)) return $refuse('lookup_failed');
    } catch (\Throwable $error) {
        return $refuse('lookup_failed');
    }
    return array('uid' => $uid, 'reason' => '');
}

/** Inspect complete NUL-separated argv, never a substring of ps output; null means scan failure. */
function pmssTerminateUserResumeRunning(string $username): ?bool
{
    $processes = glob('/proc/[0-9]*', GLOB_ONLYDIR);
    if ($processes === false || $processes === array()) return null;
    foreach ($processes as $process) {
        if ((int) basename($process) === getmypid()) continue;
        $raw = @file_get_contents($process.'/cmdline');
        if ($raw === false) {
            if (is_dir($process)) return null;
            continue; // A process may exit during the scan.
        }
        $args = explode("\0", rtrim($raw, "\0"));
        // Direct shebang execution appears as php SCRIPT ... on Linux.
        if (preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?$/D', basename($args[0])) !== 1
            || !isset($args[1]) || basename($args[1]) !== 'terminateUser.php') continue;
        foreach (array_slice($args, 2) as $arg) {
            if ($arg === '--confirm' || $arg === '--dry-run') continue;
            if ($arg === $username) return true;
            break; // Match the parser's first username, not an unrelated later argument.
        }
    }
    return false;
}

/** Share the reject/no-op/scan/dry-run/remove/audit lifecycle for direct cleanup. */
function pmssTerminateUserPathRemove(string $username, string $phase, string $path, bool $dryRun, bool $directory): bool
{
    $kind = $directory ? 'directory' : 'file';
    $ok = false;
    $status = 'ERR';
    if (pmssFilesystemPathHasNulByte($path)) {
        $message = 'Refusing unsafe '.$kind.' path';
    } else {
        if ($directory ? !is_dir($path) : !pmssPathExistsOrLink($path)) {
            return true;
        }
        // Enumeration precedes dry-run handling: an unreadable directory remains an error.
        $entries = $directory ? pmssDirectoryEntriesRead($path) : array();
        if ($entries !== false && $entries !== array()) {
            return true;
        }
        if ($entries === false) {
            $message = 'Failed to read directory';
        } elseif ($dryRun) {
            $ok = true;
            $status = 'SKIP';
            $message = $directory ? 'Dry run; directory not removed' : 'Dry run; file not removed';
        } else {
            $ok = $directory ? @rmdir($path) : @unlink($path);
            $status = $ok ? 'OK' : 'ERR';
            $message = ($ok ? 'Removed ' : 'Failed to remove ').($directory ? 'empty directory' : 'file');
        }
    }
    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, $status, $message, array('path' => $path));
    return $ok;
}

/** Preserve the file-removal entry point and its dangling-symlink behavior. */
function pmssTerminateUserUnlinkPath(string $username, string $phase, string $path, bool $dryRun): bool
{
    return pmssTerminateUserPathRemove($username, $phase, $path, $dryRun, false);
}

/** Keep absent and non-empty directories as successful, unlogged no-ops. */
function pmssTerminateUserRemoveEmptyDir(string $username, string $phase, string $path, bool $dryRun): bool
{
    return pmssTerminateUserPathRemove($username, $phase, $path, $dryRun, true);
}

/** Retire both the public route and the historical hash route. */
function pmssTerminateUserNginxRouteFileSpecs(string $username): array
{
    return array(array('phase' => 'remove_nginx_route_file', 'path' => "/etc/nginx/conf.d/pmss-user-{$username}.conf"), array('phase' => 'remove_nginx_route_file_hash', 'path' => "/etc/nginx/conf.d/pmss-user-{$username}-hash.conf"));
}

function pmssTerminateUserRemoveNginxRouteFiles(string $username, bool $dryRun): bool
{
    $ok = true;
    foreach (pmssTerminateUserNginxRouteFileSpecs($username) as $spec) {
        $ok = pmssTerminateUserUnlinkPath($username, $spec['phase'], $spec['path'], $dryRun) && $ok;
    }
    return $ok;
}
