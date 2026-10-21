<?php
/**
 * addUser: system user creation steps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__, 2).'/userLifecycle.php';
require_once dirname(__DIR__).'/directories.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/renderNodeAccess.php';
require_once dirname(__DIR__, 2).'/update/rootOwnedTree.php';

/**
 * Create the underlying Unix account and validate that `/home/<user>` is sane.
 *
 * Note: this helper intentionally exits on fatal provisioning errors to keep
 * the CLI contract stable for operators and automation.
 *
 * @param array  $user     Parsed user payload from addUser.php.
 * @param string $homePath Expected home directory path.
 */
function pmssAddUserSystemUserCreate(array $user, string $homePath): void
{
    pmssAddUserRunRequiredProvisionStep(
        'Create system user',
        sprintf('useradd --skel /etc/skel -m %s', escapeshellarg($user['name'])),
        'Create system user failed; aborting provisioning',
        'useradd_failed'
    );
    if (function_exists('pmssAddUserFailureRollbackMarkSystemUserCreated')) {
        pmssAddUserFailureRollbackMarkSystemUserCreated();
    }
    pmssEnsureIntelRenderGroupMember($user['name'], 'logProvisionMessage');

    $pwEntry = pmssUserAccountLookup($user['name']);
    if (!is_dir($homePath)) {
        pmssAddUserFatalExit('FAIL', 'Home directory missing after useradd; aborting provisioning', 'home_missing');
    }
    if (is_array($pwEntry) && isset($pwEntry['uid'])) {
        $homeOwner = @fileowner($homePath);
        if ($homeOwner !== false && (int) $homeOwner !== (int) $pwEntry['uid']) {
            pmssAddUserFatalExit('FAIL', 'Home directory owner mismatch after useradd; aborting provisioning', 'home_owner_mismatch');
        }
    }
    $screenState = pmssAddUserScreenSocketPrepare($user['name'], (int) ($pwEntry['uid'] ?? 0));
    if ($screenState === 'removed') {
        logProvisionMessage('Removed stale screen socket directory after whole-tree isolation');
    } elseif (!in_array($screenState, array('absent', 'ready'), true)) {
        pmssAddUserFatalExit('FAIL', 'Screen socket directory unavailable after useradd; aborting provisioning', 'screen_socket_'.$screenState);
    }
    if (!pmssEnsureUserHomeDir($user['name'], $homePath, '.lighttpd', 0751, 'logProvisionMessage')) {
        pmssAddUserFatalExit(
            'FAIL',
            'Lighttpd credential directory unavailable after useradd; aborting provisioning',
            'lighttpd_directory_failed'
        );
    }

    $safeChangePw = sprintf('/scripts/changePw.php %s [redacted]', escapeshellarg($user['name']));
    pmssAddUserRunRequiredProvisionStep(
        'Set initial password',
        sprintf('/scripts/changePw.php %s %s', escapeshellarg($user['name']), escapeshellarg($user['password'])),
        'Initial password update failed; aborting provisioning',
        'password_failed',
        $safeChangePw
    );

    runProvisionStep(
        'Unlock user account',
        sprintf('usermod -U %s', escapeshellarg($user['name']))
    );
    runProvisionStep(
        'Set expiry far in future',
        sprintf('usermod --expiredate 2100-01-01 %s', escapeshellarg($user['name']))
    );

    if (file_exists('/bin/bash')) {
        runProvisionStep(
            'Ensure bash shell',
            sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name']))
        );
    }
}

/**
 * Clear a previous account's screen tree before the new account starts services.
 * ADR 0121 requires whole-tree isolation before any privileged recursive removal.
 */
function pmssAddUserScreenSocketPrepare(string $userName, int $uid, string $screenDir = '/run/screen', ?callable $remover = null): string
{
    $path = $screenDir.'/S-'.$userName;
    if (!pmssUsernameIsValidForCreate($userName) || $uid < 1
        || !pmssPathTargetIsSafe($path, true, false, false)) {
        return 'unsafe';
    }

    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        return 'absent';
    }
    if (($stat['mode'] & 0170000) !== 0040000) {
        return 'unsafe';
    }
    if ((int) $stat['uid'] === $uid) {
        return 'ready';
    }

    $remover = $remover ?? 'pmssRootOwnedTreeRemove';
    $removed = $remover($path);
    clearstatcache(true, $path);
    return $removed && @lstat($path) === false ? 'removed' : 'remove_failed';
}
