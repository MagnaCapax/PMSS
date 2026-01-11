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
 */
require_once __DIR__.'/../lib/userLifecycle.php';

$usage = "Usage: setupUserHomePermissions.php USERNAME\n";
if (empty($argv[1])) die($usage);

$user = array('name' => $argv[1]);

if (!pmssValidateUsername($user['name'])) {
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'permissions',
            'validate',
            $user['name'],
            array(
                'status'  => 'ERR',
                'message' => 'Rejected username due to validation failure in setupUserHomePermissions',
            )
        )
    );
    die("Invalid username: {$user['name']}\n");
}

if (!file_exists("/home/{$user['name']}")) die("User does not exist\n");

$rtorrentRc    = "/home/{$user['name']}/.rtorrent.rc";
$rutorrentConf = "/home/{$user['name']}/www/rutorrent/conf/*";
$lighttpdDir   = "/home/{$user['name']}/.lighttpd/custom.d";

// Align with historical behaviour but quote paths defensively.
if (file_exists($rtorrentRc)) {
    pmssUserLifecycleStep(
        'permissions',
        $user['name'],
        'chown_rtorrent_rc',
        'chown root:root '.escapeshellarg($rtorrentRc),
        false
    );
    pmssUserLifecycleStep(
        'permissions',
        $user['name'],
        'chmod_rtorrent_rc',
        'chmod 775 '.escapeshellarg($rtorrentRc),
        false
    );
}

pmssUserLifecycleStep(
    'permissions',
    $user['name'],
    'chown_rutorrent_conf',
    'chown root:root '.escapeshellarg($rutorrentConf),
    false
);
pmssUserLifecycleStep(
    'permissions',
    $user['name'],
    'chmod_rutorrent_conf',
    'chmod 775 '.escapeshellarg($rutorrentConf),
    false
);

pmssUserLifecycleStep(
    'permissions',
    $user['name'],
    'chmod_lighttpd_custom',
    'chmod 750 '.escapeshellarg($lighttpdDir),
    false
);
