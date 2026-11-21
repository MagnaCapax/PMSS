<?php
/**
 * Locked web-root recovery orchestration and run summaries.
 *
 * Full recovery replaces only an empty root; partial recovery adds missing
 * managed skeleton entries and never overwrites customer-owned paths.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/webRoot.php';
require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__, 2).'/runtime/locks.php';
require_once dirname(__DIR__, 2).'/rutorrent/config.php';
require_once __DIR__.'/webRootReconcileCopy.php';
require_once __DIR__.'/webRootReconcileRestore.php';

/** Emit one structured run summary before the reconciler releases its lock. */
function pmssUserWebRootReconcileLogSummary(
    string $user,
    ?callable $logger,
    string $reason,
    string $mode,
    array $stats,
    float $startedAt
): void {
    $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
    pmssUserWebRootMigrationLog($user, $logger, sprintf(
        'web_root_reconcile reason=%s mode=%s files_restored=%d duration_ms=%d preserved_conflict=%d',
        $reason,
        $mode,
        (int) ($stats['files_restored'] ?? 0),
        $durationMs,
        (int) ($stats['preserved_conflict'] ?? 0)
    ));
}

/** Reconcile one user's web root; callers may safely retry after a lock skip. */
function pmssUserReconcileWebRoot(array $ctx, ?callable $logger = null): bool
{
    $startedAt = microtime(true);
    [$user, $home] = pmssUserContextIdentity($ctx);
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    if ($user === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $user)
        || $home === '' || !pmssPathTargetIsSafe($home, true) || is_link($home)
        || !pmssPathWithinResolvedRoot($home, $homeRoot)) {
        return false;
    }

    $lockDir = pmssResolvePathFromEnv('PMSS_USER_WEB_ROOT_LOCK_DIR', pmssRuntimeLockDir());
    $lockPath = pmssPathAbsoluteStringIsSafe($lockDir, ['allowRoot' => false])
        && is_dir($lockDir) && !is_link($lockDir)
        ? $lockDir.'/pmss-user-web-root-'.$user.'.lock' : '';
    if ($lockPath === '' || ($lock = pmssLockFileAcquire($lockPath, true)) === false) {
        pmssUserWebRootMigrationLog($user, $logger, 'Web-root reconciliation skipped because the per-user lock is busy or unsafe');
        return false;
    }

    $stats = ['files_restored' => 0, 'preserved_conflict' => 0];
    $reason = 'not-started';
    $mode = 'unknown';
    $runLogger = static function (string $message) use (&$stats, $logger): void {
        if (strpos($message, 'Preserving conflicting') !== false) {
            $stats['preserved_conflict']++;
        }
        if ($logger !== null) {
            $logger($message);
            return;
        }
        logMessage($message);
    };

    try {
        $www = $home.'/www';
        $skeleton = pmssSkeletonBase().'/www';
        if (!pmssPathSegmentsAreSafe($www, false, false)
            || !is_dir($skeleton)
            || is_link($skeleton)
            || !pmssPathTargetIsSafe($skeleton, true)) {
            $reason = 'unsafe-reconcile-source';
            return false;
        }
        if (is_link($www) || (file_exists($www) && !is_dir($www))) {
            $reason = 'unsafe-web-root';
            pmssUserWebRootMigrationLog($user, $runLogger, 'Refusing unsafe or conflicting www path');
            return false;
        }

        $entries = is_dir($www) ? pmssDirectoryEntriesRead($www) : [];
        if ($entries === false) {
            $reason = 'web-root-scan-failed';
            return false;
        }
        $fullRestore = !is_dir($www) || $entries === [];
        $mode = $fullRestore ? 'full-restore' : 'partial-merge';
        $reason = $fullRestore ? 'missing-or-empty-web-root' : 'managed-entry-check';
        $result = $fullRestore
            ? pmssUserWebRootReconcileFull($www, $skeleton, $home, $user, $runLogger, $stats)
            : pmssUserWebRootReconcileCopyEntry($skeleton, $www, $home, '', $stats, $user, $runLogger, true);
        if (!$result) {
            $reason = 'restore-failed';
            return false;
        }

        pmssUserEnsureWebRootSymlinks($ctx);
        pmssUserMigrateWebRootState($ctx, $logger);
        if ($fullRestore) {
            $configRoot = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
            if (function_exists('updateRutorrentConfig')
                && is_file($configRoot.'/template.rutorrent.config')
                && is_file($configRoot.'/template.rutorrent.access')) {
                updateRutorrentConfig($user, 1);
            } else {
                pmssUserWebRootMigrationLog($user, $logger, 'ruTorrent templates unavailable; config regeneration skipped');
            }
        }
        // Match the update path after all writers, including config regeneration.
        // The canonical repair preserves authoritative classes with inode-safe operations.
        if ($stats['files_restored'] > 0) {
            $rc = runUserStep($user, 'Refreshing restored web-root permissions',
                pmssBuildCommand('/scripts/util/userPermissions.php', [$user]));
            if ($rc !== 0) {
                $reason = 'permissions-failed';
                pmssUserWebRootMigrationLog($user, $logger, 'Restored web-root permission repair failed rc='.$rc);
                return false;
            }
        }
        return true;
    } finally {
        try {
            pmssUserWebRootReconcileLogSummary($user, $logger, $reason, $mode, $stats, $startedAt);
        } finally {
            // A summary logger fault must not strand the per-account lock.
            pmssLockHandleRelease($lock);
        }
    }
}
