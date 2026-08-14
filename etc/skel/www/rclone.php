<?php
require_once __DIR__.'/scriptsInc.php';
/** Lightweight frontend toggle for rclone. */

pmssFrontendToggleAction(
    '../.rcloneEnable',
    static function () {
        $port = (int) trim((string) @file_get_contents('../.rclonePort'));
        if ($port < 1 || $port > 65535) {
            // Without a provisioned port rclone would bind 127.0.0.1:0 (random
            // port) and the lighttpd proxy could never reach it.
            echo 'rclone port not provisioned; cannot start rclone web GUI.';
            return;
        }
        pmssFrontendShellExec("nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &");
    },
    'killall -u $(whoami) -9 rclone;'
);
