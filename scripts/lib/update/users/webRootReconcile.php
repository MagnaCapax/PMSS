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
require_once dirname(__DIR__, 2).'/runtime/locks.php';
require_once dirname(__DIR__, 2).'/rutorrent/config.php';
require_once __DIR__.'/webRootReconcileCopy.php';

/** Install a copied file without replacing an entry created concurrently. */
function pmssUserWebRootReconcileInstallFile(
    string $source,
    string $target,
    int $mode,
    bool $noReplace,
    ?array &$stats = null,
    ?string $user = null,
    string $home = ''
): bool
{
    if ($user === null) return false;
    $template = dirname($target).'/.pmss-web-root-XXXXXXXX';
    $targetArg = escapeshellarg($target);
    $command = 'tmp=$(mktemp -- '.escapeshellarg($template).') || exit 1; '
        .'trap \'rm -f -- "$tmp"\' EXIT; '
        .'cp -- '.escapeshellarg($source).' "$tmp"'
        .' && chmod '.sprintf('%04o', $mode).' -- "$tmp"'
        .' && test ! -L '.$targetArg
        .' && '.($noReplace ? 'ln -- "$tmp" '.$targetArg : 'mv -T -- "$tmp" '.$targetArg);
    $installed = pmssUserWebRootMigrationRun($user, $home, [$target], $command);
    if ($installed && $stats !== null) {
        $stats['files_restored'] = (int) ($stats['files_restored'] ?? 0) + 1;
    }
    return $installed;
}

/** Restore a complete tree only when the current root contains no entries. */
function pmssUserWebRootReconcileFull(
    string $www,
    string $skeleton,
    string $home,
    string $user,
    ?callable $logger,
    ?array &$stats = null
): bool
{
    try {
        $stage = $home.'/.pmss-web-root-reconcile-'.bin2hex(random_bytes(6));
    } catch (\Throwable $exception) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to create web-root staging name');
        return false;
    }
    if (!pmssPathAbsoluteStringIsSafe($stage, ['allowRoot' => false])
        || !pmssUserWebRootMigrationRun($user, $home, [$stage],
            'mkdir -m 0700 -- '.escapeshellarg($stage))) {
        return false;
    }

    $success = pmssUserWebRootReconcileTreeIsSafe($skeleton, $home, false)
        && pmssUserWebRootReconcileCopyEntry($skeleton, $stage, $home, '', $stats, $user)
        && pmssUserWebRootReconcileTreeIsSafe($stage, $home)
        && pmssUserWebRootReconcileApplyOwnership($stage, $user);
    if ($success) {
        $entries = pmssDirectoryEntriesRead($www);
        $currentRootIsSafe = !pmssPathExistsOrLink($www) || (!is_link($www) && $entries === []);
        $success = $currentRootIsSafe
            && pmssUserWebRootMigrationRenameAsAccount($user, $home, $stage, $www);
    }
    if (!$success) {
        pmssUserWebRootMigrationRemoveCopy($user, $home, $stage);
        pmssUserWebRootMigrationLog($user, $logger, 'Full web-root restore refused or failed');
        return false;
    }

    pmssUserWebRootMigrationLog($user, $logger, 'Restored complete web root from skeleton');
    return true;
}

/** Emit one structured run summary after the reconciler releases its lock. */
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
            : true;
        if (!$fullRestore) {
            $result = pmssUserWebRootReconcileCopyEntry($skeleton, $www, $home, '', $stats, $user, $runLogger, true);
        }
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
        return true;
    } finally {
        pmssUserWebRootReconcileLogSummary($user, $logger, $reason, $mode, $stats, $startedAt);
        pmssLockHandleRelease($lock);
    }
}
