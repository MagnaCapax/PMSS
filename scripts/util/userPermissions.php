#!/usr/bin/env php
<?php
/**
 * Utility script: user Permissions.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
# Set user folder permissions

require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/shell.php';
require_once __DIR__.'/../lib/user/permissionsCommands.php';
require_once __DIR__.'/../lib/user/permissions.php';

$usage = 'Usage: ./userPermissions.php USERNAME';
if (empty($argv[1]) ) die('need user name. ' . $usage . "\n");

['username' => $thisUser, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome((string) $argv[1], 'permissions', "Invalid username\n", "User does not exist\n");

$userIds = function_exists('pmssUserAccountLookup') ? pmssUserAccountLookup($thisUser) : null;
if (!is_array($userIds)) die("No such user\n");

// One pass per account at a time: rc.local's background sweep, update, transfer, recreate
// and provisioning all run this script, and a concurrent pass re-sets the traffic files'
// immutable flag between this pass's clear and chown (EPERM). Close-on-exec keeps the
// descriptor out of the commands below; the handle is held until exit.
$permissionsLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-userPermissions-'.$thisUser.'.lock'), false, 'ce');
if ($permissionsLock === false) {
    exit(pmssCliReturnWithStderr("Unable to lock permissions for {$thisUser}\n"));
}

pmssUserPermissionsConverge($thisUser, $homeDir, $userIds);
exit(pmssUserPermissionsResult());
