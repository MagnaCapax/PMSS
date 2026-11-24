<?php
/**
 * addUser: preflight checks for existing accounts and stale failed state.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/orphanCleanup.php';
require_once dirname(__DIR__).'/cronSpool.php';
require_once dirname(__DIR__).'/nameState.php';

/**
 * Remove only a regular cron spool whose owning UID has no current account.
 * A claimed or unusual entry needs operator inspection before username reuse.
 */
function pmssAddUserCronSpoolPreflight(string $userName, string $spoolDir = '/var/spool/cron/crontabs', ?callable $uidLookup = null): string
{
    if (!pmssUsernameIsValidForCreate($userName)) {
        return 'unsafe';
    }

    return pmssUserCronSpoolRemoveOrphan(pmssUserNameStatePaths($userName, 'cron', array('cron' => $spoolDir))[0], $uidLookup)['status'];
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

    // Do not let a reused name inherit limits, history, or account-owned socket residue.
    $residue = pmssUserNameStateResidue($user['name']);
    foreach ($residue as $entry) {
        logProvisionMessage('Username state requires '.$entry['action'].' before reuse: '.$entry['path']);
    }
    if ($residue !== array()) {
        pmssAddUserFatalExit('ERROR', 'Username state requires inspection before account creation', 'username_state_residue');
    }
}
