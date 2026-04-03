<?php
require_once __DIR__.'/../.scriptsInc.php';
/**
* PMSS: Frontend: rclone start/disable/restart file
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
* #TODO Make this dynamic and single file with definitions for all apps, deluge, qbittorrent, jellyfin, *ARR etc. https://github.com/MagnaCapax/PMSS/issues/10
**/

pmssFrontendToggleAction(
    '../.rcloneEnable',
    'startRclone',
    'killall -u $(whoami) -9 rclone;'
);

function startRclone() {
    $port = (int) trim(  file_get_contents('../.rclonePort') );
    shell_exec("nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &");
}
