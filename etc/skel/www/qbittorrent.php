<?php
require_once __DIR__.'/scriptsInc.php';
// Port enforcement is operator-side work; this customer endpoint only toggles daemons.
/** Lightweight frontend toggle for qBittorrent. */

pmssFrontendToggleAction(
    '../.qbittorrentEnable',
    static function () {
        passthru('zsh -c "qbittorrent-nox -d" >> /dev/null 2>&1 &');
    },
    'killall -u $(whoami) -9 qbittorrent-nox;',
    'killall -u $(whoami) qbittorrent-nox; sleep 3; killall -u $(whoami) -9 qbittorrent-nox'
);
