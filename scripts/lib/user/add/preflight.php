<?php
/**
 * addUser: preflight checks for existing accounts and stale failed state.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/orphanCleanup.php';

/**
 * Abort unless the target username is safe to provision right now.
 */
function pmssAddUserEnsurePreflightState(users $userDb, array $user, string $homePath): void
{
    $accountExists = static function (string $userName): bool {
        if (pmssUserAccountLookup($userName) !== null) {
            return true;
        }

        return pmssPasswdFileHasUser('/etc/passwd', $userName);
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
}
