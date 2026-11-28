<?php
/**
 * Helpers for user-facing quota snapshots.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/lighttpd/rootStage.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';

/** Publish the root-owned quota artifact with the account read group. */
function pmssQuotaSnapshotWrite(string $path, string $content, int $mode = 0640): bool
{
    $context = pmssRootStageContext($path);
    if ($context === null || $mode < 0 || $mode > 0777) return false;
    // Keep grace-bearing snapshots' mtime: the welcome page uses it as the grace origin.
    $rootCopy = pmssQuotaRootCopyPath($context[2]);
    $refreshPath = $rootCopy !== '' && $path === $context[1].'/.quota' && strpos($content, '*') === false
        ? dirname($rootCopy).'-refresh/'.$context[2] : '';
    $group = function_exists('posix_getgrnam') ? @posix_getgrnam($context[2]) : false;
    $previous = pmssReadRegularFileContentsVerified($path, 0, 4096, $metadata);
    $unchanged = $previous === $content && is_array($group)
        && ($metadata['mode'] & 07777) === $mode && $metadata['gid'] === $group['gid'];
    if ($refreshPath !== '' && $unchanged && pmssQuotaSnapshotRefreshStampWrite($refreshPath, $content, $context[2])) return true;
    if (!pmssRootStageFileWrite($path, $content, $context[2], $mode)) return false;
    // A failed runtime stamp falls back to the freshly published display mtime.
    if ($refreshPath !== '') pmssQuotaSnapshotRefreshStampWrite($refreshPath, $content, $context[2]);
    return true;
}

/** Record refresh time off /home, exposing only a digest to the account read group. */
function pmssQuotaSnapshotRefreshStampWrite(string $path, string $content, string $group): bool
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
        || !pmssEnsureSafeDir(dirname($path), 0755)) return false;
    $directory = @lstat(dirname($path));
    if (!is_array($directory) || $directory['uid'] !== 0 || ($directory['mode'] & 0022) !== 0) return false;
    return pmssWriteManagedFile($path, hash('sha256', $content)."\n", 'root', $group, 0640);
}

/** Root copy of an account's last quota snapshot; root readers use it, ~/.quota is the display copy. */
function pmssQuotaRootCopyPath(string $user): string
{
    // One plain file name per account; anything else maps to no path.
    if ($user === '' || $user[0] === '.' || strpbrk($user, "/\0") !== false) return '';
    return pmssResolvePathFromEnv('PMSS_QUOTA_RUNTIME_DIR', '/var/run/pmss/quota').'/'.$user;
}

/** Record the root copy (root:root 0600 in a 0700 directory). */
function pmssQuotaRootCopyWrite(string $user, string $content): bool
{
    $path = pmssQuotaRootCopyPath($user);
    return $path !== '' && pmssDirEnsureExists(dirname($path), 0700) && pmssAtomicWriteFile($path, $content, 0600);
}

// Preserve the existing snapshot include for display, watchdog and cron consumers.
require_once __DIR__.'/quota/snapshotText.php';
require_once __DIR__.'/quota/snapshotReport.php';
require_once __DIR__.'/quota/snapshotUsage.php';
