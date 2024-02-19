<?php
/**
* PMSS: User Frontend: qBittorrent start/disable/restart file
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
* #TODO Make this dynamic and single file with definitions for all apps, deluge, qbittorrent, jellyfin, *ARR etc. GIT Issue #10
* # https://github.com/MagnaCapax/PMSS/issues/10
**/

if (!isset($_REQUEST['action'])) die();
$action = $_REQUEST['action'];

switch($action) {
  case 'start':
    touch('../.qbittorrentEnable');
    startQbittorrent();
    break;

  case 'disable':
    unlink('../.qbittorrentEnable');
    shell_exec('killall -u $(whoami) -9 qbittorrent-nox;');
    break;

  case 'restart':
    shell_exec('killall -u $(whoami) qbittorrent-nox; sleep 3; killall -u $(whoami) -9 qbittorrent-nox');
    startQbittorrent();
    break;

}

function startQbittorrent() {    // this actually calls the function to start rTorrent :)
    passthru('python3 /home/$(whoami)/.qbittorrentPort.py; zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &');
}

