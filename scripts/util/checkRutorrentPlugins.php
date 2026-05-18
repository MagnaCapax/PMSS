#!/usr/bin/env php
<?php
/**
 * Utility script: check Rutorrent Plugins.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/rutorrentPlugins.php';

function pmssCheckRutorrentPluginsMain(array $argv): int
{
    echo date('Y-m-d H:i:s') . ': Checking rTorrent instances' . "\n";

    $accessIni = @file_get_contents('/etc/seedbox/config/template.rutorrent.access');
    if ($accessIni === false) {
        fwrite(STDERR, "Unable to read /etc/seedbox/config/template.rutorrent.access\n");
        return 1;
    }

    $users = pmssListManagedUsers('/scripts/listUsers.php');
    foreach ($users as $thisUser) {
        echo "\nChecking: {$thisUser}\n";
        pmssCheckRutorrentPluginsSyncUser($thisUser, $accessIni);
    }

    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssCheckRutorrentPluginsMain');
