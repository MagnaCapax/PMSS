#!/usr/bin/env php
<?php
/**
 * Repair ownership drift in one account's payload on explicit operator request.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lib/user/identity.php';
require_once __DIR__.'/../lib/user/dataOwnershipRepair.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

/** Validate one account and serialize its opt-in data repair with routine permissions. */
function pmssUserDataOwnershipRepairCli(array $argv): int
{
    if (count($argv) !== 2 || !is_string($argv[1]) || !pmssValidateUsername($argv[1])
        || pmssUsernameIsReserved($argv[1])) {
        return pmssCliReturnWithStderr("Usage: userDataOwnershipRepair.php USERNAME (PMSS_DRY_RUN=1 previews)\n");
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return pmssCliReturnWithStderr("Data ownership repair must be invoked as root\n");
    }

    $user = $argv[1];
    $home = '/home/'.$user;
    $account = pmssUserAccountLookup($user);
    $uid = pmssPasswdEntryPositiveUid($account);
    if ($uid === null || !isset($account['gid']) || $account['gid'] <= 0
        || ($account['dir'] ?? '') !== $home || @fileowner($home) !== $uid) {
        return pmssCliReturnWithStderr("Missing or unsafe account home or identity\n");
    }

    // This is deliberately absent from boot/update/transfer: large payload walks are opt-in.
    $command = pmssUserDataOwnershipRepairCommand(
        $home, $uid, (int) $account['gid'], pmssSubordinateIdRanges($user, $uid, 'uid')
    );
    if ($command === null) {
        return pmssCliReturnWithStderr("Missing or unsafe data directory\n");
    }
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        return runStep('Repairing data ownership', $command);
    }

    $lock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-userPermissions-'.$user.'.lock'), false, 'ce');
    if ($lock === false) {
        return pmssCliReturnWithStderr("Unable to lock account permissions\n");
    }
    try {
        return runStep('Repairing data ownership', $command);
    } finally {
        fclose($lock);
    }
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserDataOwnershipRepairCli');
