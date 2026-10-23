<?php
/** Account-owned Transmission controls; the disable marker always precedes a stop. */
require_once __DIR__.'/scriptsInc.php';

pmssFrontendPostActionRequired();
$action = pmssFrontendActionRequest();
if (!in_array($action, array('start', 'disable', 'restart'), true)) {
    http_response_code(400);
    return;
}
$home = dirname(__DIR__);
$marker = $home.'/.transmissionDisable';
$dir = $home.'/.config/pmss/torrentClients';
// Serialize Apps actions with Configure and the watchdog's account-side launch.
if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)
    || is_link($dir.'/transmission.lock')) {
    http_response_code(503);
    return;
}
$lock = @fopen($dir.'/transmission.lock', 'ce');
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) fclose($lock);
    http_response_code(409);
    return;
}
try {
    $binary = pmssCustomerTorrentClientSelectedBinary('transmission');
    if ($binary === null) {
        http_response_code(409);
        return;
    }
    if ($action === 'restart' && is_file($marker)) {
        http_response_code(409);
        return;
    }
    if ($action === 'disable') {
        // Leave an existing marker untouched; never stop if persistence failed.
        if (!is_file($marker) && (is_link($marker) || !@touch($marker) || !is_file($marker))) {
            http_response_code(500);
            return;
        }
        pmssCustomerNativeStop(array($binary));
        return;
    }
    if ($action === 'start' && (file_exists($marker) || is_link($marker)) && !@unlink($marker)) {
        http_response_code(500);
        return;
    }
    if ($action === 'restart') pmssCustomerNativeStop(array($binary));
    // Use the same resolver CLI as cron; it owns settings checks and daemon flock.
    $command = 'cd '.escapeshellarg($home).' && php '
        .escapeshellarg($home.'/www/torrentClientsResolve.php')
        .' transmission --launch >/dev/null 2>&1';
    if (trim((string) pmssFrontendShellExec($command.'; printf "%s" "$?"')) !== '0') {
        http_response_code(503);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
