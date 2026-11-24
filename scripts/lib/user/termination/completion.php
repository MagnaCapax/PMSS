<?php
/** Preinstall-state retirement and final termination verification.
 * Loaded by terminationCleanup.php; public entry points retain their contracts.
 * @license GPL-3.0-only
 */

/** Keep the preinstall marker while a passwd entry can still own the name. */
function pmssTerminateUserClearPreinstallState(string $username, bool $dryRun, bool $accountPresent, ?string $stateDir = null, ?callable $logger = null): string
{
    if ($dryRun) {
        $status = 'SKIP';
        $message = 'Dry run; marker not removed';
    } elseif ($accountPresent) {
        $status = 'SKIP';
        $message = 'Passwd entry remains; marker retained';
    } else {
        $cleared = $stateDir === null
            ? pmssPikkumiesPreinstallStateClear($username)
            : pmssPikkumiesPreinstallStateClear($username, $stateDir);
        $status = $cleared ? 'OK' : 'ERR';
        $message = $cleared ? 'Marker cleared or absent' : 'Failed to clear marker';
    }
    $log = $logger ?? 'pmssUserLifecycleContextLogStatusMessage';
    $log('terminate', 'clear_pikkumies_preinstall_state', $username, $status, $message);
    if (!$dryRun && !$accountPresent && $stateDir === null) {
        $askCleared = pmssPikkumiesAskStateClear($username);
        $log('terminate', 'clear_pikkumies_ask_state', $username, $askCleared ? 'OK' : 'ERR',
            $askCleared ? 'Detached-ask files cleared or absent' : 'Failed to clear detached-ask files');
    }
    return $status;
}

/** Retire the monthly IOPS budget state once the name is unassigned, so a later account with the name starts unheld. */
function pmssTerminateUserClearIopsBudgetState(string $username, bool $dryRun, ?string $runtimeRoot = null): bool
{
    if (!pmssUsernameIsValid($username)) {
        throw new \InvalidArgumentException('Refusing unsafe IOPS budget cleanup');
    }
    if (pmssUserAccountLookup($username) !== null) return false;
    $root = rtrim(pmssDirPathResolve($runtimeRoot, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime'), '/');
    $ok = true;
    // Inventory additions receive the same unassigned-name guard and audited removal.
    foreach (pmssUserNameStatePaths($username, 'iops', array('iops' => $root)) as $path) {
        $ok = pmssTerminateUserUnlinkPath($username, 'remove_iops_budget_state', $path, $dryRun) && $ok;
    }
    return $ok;
}

/**
 * Move the account's per-user resource and traffic history aside once the name is unassigned. The history is
 * kept (ADR 0044 retention), but a later account with the same name no longer reads it as its own usage.
 * The log directories are root-owned; the archive directory is root-only.
 */
function pmssTerminateUserArchiveUsageHistory(string $username, bool $dryRun, ?string $logRoot = null, ?int $now = null, ?string $cacheRoot = null): bool
{
    if (!pmssUsernameIsValid($username)) {
        throw new \InvalidArgumentException('Refusing unsafe usage history archive');
    }
    if (pmssUserAccountLookup($username) !== null) return false;
    $root = rtrim($logRoot ?? '/var/log/pmss', '/');
    $stamp = gmdate('YmdHis', $now ?? time());
    $ok = true;
    foreach (pmssUserNameStatePaths($username, 'usage', array('usage' => $root)) as $path) {
        if (!is_file($path) || is_link($path)) continue;
        // A subdirectory, so the stats crons that list every file in the log directory do not pick it up.
        $archiveDir = dirname($path).'/terminated';
        $target = $archiveDir.'/'.basename($path).'.'.$stamp;
        if ($dryRun) {
            pmssUserLifecycleContextLogStatusMessage('terminate', 'archive_usage_history', $username, 'SKIP', 'Dry run; usage history not moved', array('path' => $path));
            continue;
        }
        $moved = (is_dir($archiveDir) || @mkdir($archiveDir, 0700)) && !is_link($archiveDir) && @rename($path, $target);
        pmssUserLifecycleContextLogStatusMessage('terminate', 'archive_usage_history', $username, $moved ? 'OK' : 'ERR',
            $moved ? 'Moved usage history aside' : 'Failed to move usage history aside', array('path' => $path, 'target' => $target));
        $ok = $moved && $ok;
    }
    // The stats caches are rebuilt from these logs; with the logs moved, a stale cache would stay as the new account's usage.
    $cacheRoot = rtrim(pmssDirPathResolve($cacheRoot, 'PMSS_RUNTIME_DIR', '/var/run/pmss'), '/');
    foreach (pmssUserNameStatePaths($username, 'cache', array('cache' => $cacheRoot)) as $cache) {
        if (!is_file($cache) || is_link($cache)) continue;
        if ($dryRun) {
            pmssUserLifecycleContextLogStatusMessage('terminate', 'remove_usage_cache', $username, 'SKIP', 'Dry run; usage cache not removed', array('path' => $cache));
            continue;
        }
        $gone = @unlink($cache);
        pmssUserLifecycleContextLogStatusMessage('terminate', 'remove_usage_cache', $username, $gone ? 'OK' : 'ERR',
            $gone ? 'Removed usage cache' : 'Failed to remove usage cache', array('path' => $cache));
        $ok = $gone && $ok;
    }
    return $ok;
}

/** Retire a raced linger flag only after the account name is no longer assigned. */
function pmssTerminateUserClearLinger(string $username, bool $dryRun): bool
{
    if (!pmssUsernameIsValid($username)) {
        throw new \InvalidArgumentException('Refusing unsafe linger cleanup');
    }
    if (pmssUserAccountLookup($username) !== null) return false;
    // The systemd directory is root-controlled; unlink only this exact entry, never its target.
    return pmssTerminateUserUnlinkPath($username, 'remove_stale_linger', pmssUserNameStatePaths($username, 'linger')[0], $dryRun);
}

/** Return the final line, exit code, and audit status after every purge has run. */
function pmssTerminateUserCompletion(string $username, array $paths, array $purgeResults, bool $dryRun, string $lingerRoot = '/var/lib/systemd/linger'): array
{
    if ($dryRun) {
        return array('line' => '## Done. User termination flow completed (dry run).', 'rc' => 0, 'status' => 'OK');
    }

    // Every step must have run. The first removal fails by design when immutable files remain
    // (PMSS writes ~/.trafficData* and ~/.resourceData immutable), so only the immutable clear
    // and the leftover removal decide; a later successful retry does not erase their failure.
    foreach (array('home', 'user_backup', 'user_backup_superseded') as $label) {
        foreach (array('remove_'.$label.'_initial', 'clear_immutable_'.$label, 'remove_'.$label.'_leftovers') as $step) {
            if (!isset($purgeResults[$step])
                || ($purgeResults[$step] !== 0 && $step !== 'remove_'.$label.'_initial')) {
                return array('line' => '## Incomplete: '.$step, 'rc' => 1, 'status' => 'INCOMPLETE');
            }
        }
    }
    if (pmssUserAccountLookup($username) !== null) {
        return array('line' => '## Incomplete: account_still_present', 'rc' => 1, 'status' => 'INCOMPLETE');
    }
    $lingerPath = rtrim($lingerRoot, '/').'/'.$username;
    clearstatcache(true, $lingerPath);
    if (pmssPathExistsOrLink($lingerPath)) {
        return array('line' => '## Incomplete: linger_still_present', 'rc' => 1, 'status' => 'INCOMPLETE');
    }
    foreach ($paths as $label => $path) {
        clearstatcache(true, $path);
        if (pmssPathExistsOrLink($path)) {
            return array('line' => '## Incomplete: '.$label.'_still_present', 'rc' => 1, 'status' => 'INCOMPLETE');
        }
    }
    return array('line' => '## Done. User termination verified.', 'rc' => 0, 'status' => 'OK');
}
