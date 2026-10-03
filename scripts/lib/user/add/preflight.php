<?php
/**
 * addUser: preflight checks for existing accounts and stale failed state.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/orphanCleanup.php';

/**
 * Remove only a regular cron spool whose owning UID has no current account.
 * A claimed or unusual entry needs operator inspection before username reuse.
 */
function pmssAddUserCronSpoolPreflight(string $userName, string $spoolDir = '/var/spool/cron/crontabs', ?callable $uidLookup = null): string
{
    if (!pmssUsernameIsValidForCreate($userName)) {
        return 'unsafe';
    }

    $path = $spoolDir.'/'.$userName;
    $stat = @lstat($path);
    if ($stat === false) {
        return 'absent';
    }
    if (($stat['mode'] & 0170000) !== 0100000) {
        return 'unsafe';
    }

    $uidLookup = $uidLookup ?? 'posix_getpwuid';
    if (!is_callable($uidLookup) || $uidLookup((int) $stat['uid']) !== false) {
        return 'claimed';
    }

    if (!@unlink($path)) {
        return 'remove_failed';
    }
    clearstatcache(true, $path);
    return @lstat($path) === false ? 'removed' : 'remove_failed';
}

/**
 * Abort unless the target username is safe to provision right now.
 */
function pmssAddUserEnsurePreflightState(users $userDb, array $user, string $homePath): void
{
    $accountExists = static function (string $userName): bool {
        if (pmssUserAccountLookup($userName) !== null) {
            return true;
        }

        return pmssPasswdEntryLookup($userName) !== null;
    };

    $userExists = $accountExists($user['name']);
    if ($userExists && pmssAddUserFailedProvisionCanRecover($user['name'])) {
        logProvisionMessage('Detected recent failed provisioning attempt with inactive services; cleaning stale account before retry');
        if (!pmssAddUserCleanupFailedProvision($userDb, $user['name'], $homePath)) {
            pmssAddUserFatalExit('ERROR', 'Failed provisioning cleanup left stale resources; refusing to continue', 'failed_provision_cleanup_failed');
        }
        $userExists = $accountExists($user['name']);
    }

    if ($userExists) {
        pmssAddUserFatalExit('ERROR', 'User already exists; refusing to overwrite', 'user_exists');
    }

    if (is_dir($homePath)) {
        pmssAddUserFatalExit('ERROR', 'Home directory exists without passwd entry; refusing to clobber', 'orphaned_home');
    }

    $cronSpoolState = pmssAddUserCronSpoolPreflight($user['name']);
    if ($cronSpoolState === 'removed') {
        logProvisionMessage('Removed orphaned cron spool before account creation');
    } elseif ($cronSpoolState !== 'absent') {
        pmssAddUserFatalExit('ERROR', 'Cron spool entry requires inspection before account creation', 'cron_spool_'.$cronSpoolState);
    }
}
