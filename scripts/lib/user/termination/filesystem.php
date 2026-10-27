<?php
/** Guarded direct path removal and nginx route retirement.
 * Loaded by terminationCleanup.php; public entry points retain their contracts.
 * @license GPL-3.0-only
 */

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
