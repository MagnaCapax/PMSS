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
    if ($context === null) return false;
    return pmssRootStageFileWrite($path, $content, $context[2], $mode);
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
