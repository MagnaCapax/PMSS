<?php
require_once __DIR__.'/../.scriptsInc.php';
// /scripts/lib/user/torrentPort.php require removed 2026-05-17 per ADR 0016:
// customer PHP cannot read /scripts/ (operator-only 750 root:root). The
// is_readable() guard above always returned false silently. Port
// enforcement is operator-cron territory.
/**
* PMSS: User Frontend: Deluge start/disable/restart file
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
* #TODO Make this dynamic and single file with definitions for all apps, deluge, qbittorrent, jellyfin, *ARR etc. https://github.com/MagnaCapax/PMSS/issues/10
**/

pmssFrontendToggleAction(
    '../.delugeEnable',
    'startDeluge',
    'killall -u $(whoami) -9 deluged; killall -u $(whoami) -9 deluge-web'
);

function startDeluge() {
    if (function_exists('pmssDelugePortEnsureCurrentUser')) {
        pmssDelugePortEnsureCurrentUser();
    }
    shell_exec('nohup deluged -l /home/$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');
}
