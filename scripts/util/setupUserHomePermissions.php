#!/usr/bin/env php
<?php
/**
 * Normalise legacy user home permissions for ruTorrent and Lighttpd artefacts.
 *
 * This helper predates userPermissions.php and is retained to avoid breaking
 * older operational flows. New callers should prefer the consolidated
 * userPermissions.php script.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/user/userFilesystem.php';

$usage = "Usage: setupUserHomePermissions.php USERNAME\n";
if (empty($argv[1])) die($usage);

['username' => $userName, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome((string) $argv[1], 'permissions', "Invalid username: %s\n", "User does not exist\n", 'Rejected username due to validation failure in setupUserHomePermissions');

$rtorrentRc    = $homeDir.'/.rtorrent.rc';
$rutorrentConf = $homeDir.'/www/rutorrent/conf/*';
$lighttpdDir   = $homeDir.'/.lighttpd/custom.d';

// Align with historical behaviour but quote paths defensively.
if (file_exists($rtorrentRc)) {
    pmssUserLifecycleRunSteps('permissions', $userName, array(
        array('chown_rtorrent_rc', 'chown root:root '.escapeshellarg($rtorrentRc)),
        array('chmod_rtorrent_rc', 'chmod 775 '.escapeshellarg($rtorrentRc)),
    ), false);
}

pmssUserLifecycleRunSteps('permissions', $userName, array(
    array('chown_rutorrent_conf', 'chown root:root '.escapeshellarg($rutorrentConf)),
    array('chmod_rutorrent_conf', 'chmod 775 '.escapeshellarg($rutorrentConf)),
    array('chmod_lighttpd_custom', 'chmod 750 '.escapeshellarg($lighttpdDir)),
), false);
