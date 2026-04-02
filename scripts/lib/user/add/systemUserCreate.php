<?php
/**
 * addUser: system user creation steps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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

    $pwEntry = null;
    if (function_exists('posix_getpwnam')) {
        $pwEntry = @posix_getpwnam($user['name']);
    }
    if (!is_dir($homePath)) {
        pmssAddUserFatalExit('FAIL', 'Home directory missing after useradd; aborting provisioning', 'home_missing');
    }
    if (is_array($pwEntry) && isset($pwEntry['uid'])) {
        $homeOwner = @fileowner($homePath);
        if ($homeOwner !== false && (int) $homeOwner !== (int) $pwEntry['uid']) {
            pmssAddUserFatalExit('FAIL', 'Home directory owner mismatch after useradd; aborting provisioning', 'home_owner_mismatch');
        }
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
