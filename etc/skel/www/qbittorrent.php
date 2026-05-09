<?php
require_once __DIR__.'/../.scriptsInc.php';
if (is_readable('/scripts/lib/user/torrentPort.php')) {
    require_once '/scripts/lib/user/torrentPort.php';
}
/**
* PMSS: User Frontend: qBittorrent start/disable/restart file
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
* #TODO Make this dynamic and single file with definitions for all apps, deluge, qbittorrent, jellyfin, *ARR etc. GIT Issue #10
* # https://github.com/MagnaCapax/PMSS/issues/10
**/

pmssFrontendToggleAction(
    '../.qbittorrentEnable',
    'startQbittorrent',
    'killall -u $(whoami) -9 qbittorrent-nox;',
    'killall -u $(whoami) qbittorrent-nox; sleep 3; killall -u $(whoami) -9 qbittorrent-nox'
);

function startQbittorrent() {    // this actually calls the function to start rTorrent :)
    if (function_exists('pmssQbittorrentPortEnsureCurrentUser')) {
        pmssQbittorrentPortEnsureCurrentUser();
    }
    passthru('zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &');
}
