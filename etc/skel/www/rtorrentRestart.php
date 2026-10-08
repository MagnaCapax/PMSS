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
    pmssCustomerNativeSignal(array('/usr/bin/rtorrent', '/usr/local/bin/rtorrent'), 15);
} elseif ($action === 'start') {
    if (is_file($marker) && !@unlink($marker)) { http_response_code(500); exit; }
    @touch(__DIR__.'/.rtorrentRestart');
} elseif ($action === '' || $action === 'restart') {
    @touch(__DIR__.'/.rtorrentRestart');
} else {
    http_response_code(400);
}
