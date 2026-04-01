<?php
/**
 * addUser: preflight checks for existing accounts and stale failed state.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/orphanCleanup.php';

/**
 * Match the historical passwd fallback used by addUser preflight.
 */
function pmssAddUserAccountExists(string $userName): bool
{
    if (pmssUserAccountLookup($userName) !== null) {
        return true;
    }

    $passwd = @file_get_contents('/etc/passwd');
    return $passwd !== false && preg_match('/^'.preg_quote($userName, '/').':/m', $passwd) === 1;
}

/**
 * Abort unless the target username is safe to provision right now.
 */
function pmssAddUserEnsurePreflightState(users $userDb, array $user, string $homePath): void
{
    $userExists = pmssAddUserAccountExists($user['name']);
    if ($userExists && pmssAddUserFailedProvisionCanRecover($user['name'])) {
        logProvisionMessage('Detected recent failed provisioning attempt with inactive services; cleaning stale account before retry');
        if (!pmssAddUserCleanupFailedProvision($userDb, $user['name'], $homePath)) {
            logProvisionMessage('FATAL: Failed provisioning cleanup left stale resources; refusing to continue');
            finalizeProvision('ERROR', 'failed_provision_cleanup_failed', 1);
            exit(1);
        }
        $userExists = pmssAddUserAccountExists($user['name']);
    }

    if ($userExists) {
        logProvisionMessage('FATAL: User already exists; refusing to overwrite');
        finalizeProvision('ERROR', 'user_exists', 1);
        exit(1);
    }

    if (is_dir($homePath)) {
        logProvisionMessage('FATAL: Home directory exists without passwd entry; refusing to clobber');
        finalizeProvision('ERROR', 'orphaned_home', 1);
        exit(1);
    }
}
