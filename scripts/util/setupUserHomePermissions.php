#!/usr/bin/env php
<?php
/**
 * Normalise legacy user home permissions for ruTorrent and Lighttpd artefacts.
 *
 * This helper predates userPermissions.php and is retained to avoid breaking
 * older operational flows. New callers should prefer the consolidated
 * userPermissions.php script.
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/pathSafety.php';
require_once __DIR__.'/../lib/user/userFilesystem.php';
require_once __DIR__.'/../lib/user/permissionsApply.php';

$usage = "Usage: setupUserHomePermissions.php USERNAME\n";
if (empty($argv[1])) die($usage);

['username' => $userName, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome((string) $argv[1], 'permissions', "Invalid username: %s\n", "User does not exist\n", 'Rejected username due to validation failure in setupUserHomePermissions');

$rtorrentRc    = $homeDir.'/.rtorrent.rc';
$rutorrentConf = $homeDir.'/www/rutorrent/conf/*';
$lighttpdDir   = $homeDir.'/.lighttpd/custom.d';

// The account owns its home and can replace entries while this runs, so each change
// acts on the checked object (scripts/lib/user/permissionsApply.php): owners with
// chown -h run by -execdir from a find anchored at the home, modes on root-owned
// files through the opened file, and modes on account-owned entries as the account.
$account = posix_getpwnam($userName);
if (!is_array($account)) die("User does not exist\n");
$accountUid = (int) $account['uid'];

foreach (array_merge(pmssPathShellTargetPaths($rtorrentRc), pmssPathShellTargetPaths($rutorrentConf)) as $target) {
    pmssUserPermissionsOwnerApply($homeDir, $target, 'root:root');
    pmssUserPermissionsModeApply($userName, $homeDir, $accountUid, $target, 0775);
}
pmssUserPermissionsModeApply($userName, $homeDir, $accountUid, $lighttpdDir, 0750);

if (!empty($GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'])) {
    pmssUserLog($userName, sprintf(
        'setupUserHomePermissions: %d step(s) failed, first: %s',
        (int) $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'],
        (string) ($GLOBALS['PMSS_USER_PERMISSIONS_FIRST_FAILED_COMMAND'] ?? '')
    ));
}
