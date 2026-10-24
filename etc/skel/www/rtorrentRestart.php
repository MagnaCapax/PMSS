<?php
/**
* PMSS: User Front-End Request rTorrent Restart
* 
* #TODO Probably could just send SIGHUP // kill ...
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
**/

require_once __DIR__.'/scriptsInc.php';
pmssFrontendPostActionRequired();
$action = pmssFrontendActionRequest();
$marker = __DIR__.'/../.rtorrentDisable';
if ($action === 'stop') {
    if (!@touch($marker)) { http_response_code(500); exit; }
    pmssCustomerNativeSignal(pmssCustomerRtorrentBinaries(), 15);
} elseif ($action === 'start') {
    if (is_file($marker) && !@unlink($marker)) { http_response_code(500); exit; }
    pmssRtorrentRestartRequest(dirname(__DIR__));
} elseif ($action === 'restart') {
    pmssRtorrentRestartRequest(dirname(__DIR__));
} else {
    http_response_code(400);
}

/** Leave the marker and run its consumer now; accounts made since user crontabs stopped being seeded have no cron line for it. */
function pmssRtorrentRestartRequest(string $home): void
{
    if (!@touch($home.'/www/.rtorrentRestart')) { http_response_code(500); exit; }
    if (!is_file($home.'/.rtorrentRestart.php') || !pmssFrontendFunctionAvailable('exec')) return;
    // The consumer takes about 20 s and removes the marker first, so a cron line that also runs it finds nothing to do.
    @exec('cd '.escapeshellarg($home).' && nohup php ./.rtorrentRestart.php >/dev/null 2>&1 &');
}
