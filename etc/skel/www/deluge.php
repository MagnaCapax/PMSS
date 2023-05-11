<?php
/**
* PMSS: Master GUI: Deluge start/disable/restart file
*
* Copyright (C) 2010-2023 Magna Capax Finland Oy
*
* #TODO Make this dynamic and single file with definitions for all apps, deluge, qbittorrent, jellyfin, *ARR etc.
**/

if (!isset($_REQUEST['action'])) die();
$action = $_REQUEST['action'];

switch($action) {
  case 'start':
    touch('../.delugeEnable');
    startDeluge();
    break;

  case 'disable':
    unlink('../.delugeEnable');
    shell_exec('killall -u $(whoami) -9 deluged; killall -u $(whoami) -9 deluge-web');
    break;

  case 'restart':
    shell_exec('killall -u $(whoami) -9 deluged; killall -u $(whoami) -9 deluge-web');
    startDeluge();
    break;

}

function startDeluge() {
    shell_exec('nohup deluged -l /home/$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');
}
