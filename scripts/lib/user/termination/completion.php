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
