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
    pmssUserLifecycleStep(
        'permissions',
        $userName,
        'chown_rtorrent_rc',
        'chown root:root '.escapeshellarg($rtorrentRc),
        false
    );
    pmssUserLifecycleStep(
        'permissions',
        $userName,
        'chmod_rtorrent_rc',
        'chmod 775 '.escapeshellarg($rtorrentRc),
        false
    );
}

$steps = [
    ['chown_rutorrent_conf', 'chown root:root '.escapeshellarg($rutorrentConf)],
    ['chmod_rutorrent_conf', 'chmod 775 '.escapeshellarg($rutorrentConf)],
    ['chmod_lighttpd_custom', 'chmod 750 '.escapeshellarg($lighttpdDir)],
];
foreach ($steps as $step) {
    pmssUserLifecycleStep('permissions', $userName, $step[0], $step[1], false);
}
