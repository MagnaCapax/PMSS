<?php
/**
* PMSS: Master GUI: rclone start/disable/restart file
*
* Copyright (C) 2010-2023 Magna Capax Finland Oy
*
* #TODO Make this dynamic and single file with definitions for all apps, deluge, qbittorrent, jellyfin, *ARR etc.
**/

if (!isset($_REQUEST['action'])) die();
$action = $_REQUEST['action'];

switch($action) {
  case 'start':
    touch('../.rcloneEnable');
    startRclone();
    break;

  case 'disable':
    unlink('../.rcloneEnable');
    shell_exec('killall -u $(whoami) -9 rclone;');
    break;

  case 'restart':
    shell_exec('killall -u $(whoami) -9 rclone;');
    startRclone();
    break;

}

function startRclone() {
    $port = (int) trim(  file_get_contents('../.rclonePort') );
    shell_exec("nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &");
}
