<?php
/**
 * Termination cleanup helpers shared by foreground account removal flows.
 *
 * The operator script keeps orchestration order; this module owns the small
 * filesystem cleanup primitives and reclaim-path moves used during termination.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once __DIR__.'/homeReclaim.php';

/** Remove a lifecycle-owned file while preserving dry-run semantics. */
function pmssTerminateUserUnlinkPath(string $username, string $phase, string $path, bool $dryRun): bool
{
    if (!file_exists($path) && !is_link($path)) return true;
    if ($dryRun) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'SKIP', 'Dry run; file not removed', array('path' => $path));
        return true;
    }
    if (@unlink($path)) { pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'OK', 'Removed file', array('path' => $path)); return true; }
    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Failed to remove file', array('path' => $path));
    return false;
}

/** Remove an empty lifecycle-owned directory without hiding dry-run mutations. */
function pmssTerminateUserRemoveEmptyDir(string $username, string $phase, string $path, bool $dryRun): bool
{
    if (!is_dir($path)) return true;
    $entries = @scandir($path);
    if (!is_array($entries) || array_diff($entries, array('.', '..')) !== array()) return true;
    if ($dryRun) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'SKIP', 'Dry run; directory not removed', array('path' => $path));
        return true;
    }
    if (@rmdir($path)) { pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'OK', 'Removed empty directory', array('path' => $path)); return true; }
    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Failed to remove empty directory', array('path' => $path));
    return false;
}

/** Remove nginx subdomain route files owned by this terminated user. */
function pmssTerminateUserRemoveNginxRouteFiles(string $username, bool $dryRun): bool
{
    $ok = true;
    foreach (array('' => 'remove_nginx_route_file', '-hash' => 'remove_nginx_route_file_hash') as $suffix => $phase) {
        $ok = pmssTerminateUserUnlinkPath($username, $phase, "/etc/nginx/conf.d/pmss-user-{$username}{$suffix}.conf", $dryRun) && $ok;
    }
    return $ok;
}

/** Move a validated source path into the asynchronous reclaim namespace. */
function pmssTerminateUserMovePathForReclaim(string $username, string $phase, string $sourcePath, bool $dryRun, string $dryRunMessage, string $failureMessage, string $successMessage, array $allocationContext = array(), bool $sourceUnsafe = false): string
{
    $targetPath = pmssUserHomeReclaimPathNext($username);
    if ($targetPath === '') {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', 'Unable to allocate reclaim path', $allocationContext);
        return '';
    }

    $context = array('source' => $sourcePath, 'target' => $targetPath);
    if ($dryRun) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'SKIP', $dryRunMessage, $context);
        return $targetPath;
    }
    if ($sourceUnsafe || !pmssUserHomeReclaimPathIsSafe($targetPath) || !@rename($sourcePath, $targetPath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'ERR', $failureMessage, $context);
        return '';
    }
    pmssUserLifecycleContextLogStatusMessage('terminate', $phase, $username, 'OK', $successMessage, $context);
    return $targetPath;
}

/** Move the home out of the active username namespace before slow disk reclaim. */
function pmssTerminateUserMoveHomeForReclaim(string $username, string $homePath, bool $dryRun): string
{
    return pmssTerminateUserMovePathForReclaim($username, 'home_reclaim_rename', $homePath, $dryRun, 'Dry run; home not renamed', 'Failed to rename home for background reclaim', 'Renamed home for background reclaim', array(), is_link($homePath));
}

/** Move a recreateUser safety backup into the asynchronous reclaim namespace. */
function pmssTerminateUserMoveBackupForReclaim(string $username, string $backupPath, bool $dryRun): string
{
    if (is_link($backupPath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'reclaim_user_backup_dir', $username, 'ERR', 'Refusing symlinked user backup directory', array('source' => $backupPath));
        return '';
    }
    if (!is_dir($backupPath)) return '';
    $realBackup = realpath($backupPath);
    if ($realBackup === false || $realBackup !== $backupPath) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'reclaim_user_backup_dir', $username, 'ERR', 'Refusing unexpected user backup path', array('source' => $backupPath, 'real_backup' => $realBackup));
        return '';
    }
    return pmssTerminateUserMovePathForReclaim($username, 'reclaim_user_backup_dir', $backupPath, $dryRun, 'Dry run; user backup not renamed', 'Failed to rename user backup for background reclaim', 'Renamed user backup for background reclaim', array('source' => $backupPath));
}
