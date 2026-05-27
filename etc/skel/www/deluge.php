<?php
require_once __DIR__.'/../.scriptsInc.php';
// Port enforcement is operator-side work; this customer endpoint only toggles daemons.
/** Lightweight frontend toggle for Deluge. */

pmssFrontendToggleAction(
    '../.delugeEnable',
    'startDeluge',
    'killall -u $(whoami) -9 deluged; killall -u $(whoami) -9 deluge-web'
);

function startDeluge() {
    pmssFrontendShellExec('nohup deluged -l /home/$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');
}
