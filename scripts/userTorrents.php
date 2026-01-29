#!/usr/bin/env php
<?php
/**
 * Print per-user torrent counts from rTorrent session directories.
 *
 * Intended for quick operational overview of how many torrents each tenant
 * has active by inspecting /home/<user>/session/*.torrent files.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/lib/userLifecycle.php';

// Get & parse users list
$users = explode("\n", trim((string) shell_exec('/scripts/listUsers.php')));
$changedConfig = array();

foreach($users AS $thisUser) {    // Loop users checking their instances
    $thisUser = trim($thisUser);
    if ($thisUser === '') {
        continue;
    }
    if (!pmssValidateUsername($thisUser)) {
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'torrents',
                'validate',
                $thisUser,
                [
                    'status'  => 'ERR',
                    'message' => 'Skipping invalid username in userTorrents',
                ]
            )
        );
        continue;
    }
    $torrents = glob("/home/{$thisUser}/session/*.torrent");
    echo "{$thisUser}: " . number_format(count($torrents)) . "\n";
} 
