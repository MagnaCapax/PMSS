#!/usr/bin/env php
<?php
/** Reclaim old, ownerless legacy rTorrent port reservation markers. */

require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/rtorrentPortReservationsReconcile.php';

/** Run one fail-closed reconciliation pass for root cron. */
function pmssRtorrentPortReservationsReconcileMain(): int
{
    $homeHandle = is_dir('/home') && !is_link('/home') ? @opendir('/home') : false;
    $passwd = pmssReadRegularFileContents('/etc/passwd');
    if ($homeHandle === false || $passwd === null || preg_match('/(?:^|\n)root:[^:\n]*:/', $passwd) !== 1) {
        if (is_resource($homeHandle)) {
            @closedir($homeHandle);
        }
        echo "{\"status\":\"skipped\",\"reason\":\"user_enumeration_unavailable\"}\n";
        return 0;
    }
    @closedir($homeHandle);

    $result = pmssRtorrentPortReservationsReconcile(pmssManagedHomeUsersList(true));
    $level = $result['status'] === 'error' ? 'error' : ($result['status'] === 'skipped' ? 'warn' : 'info');
    $encoded = pmssJsonEncodeSafe(array_merge(array(
        'timestamp' => date('c'),
        'event' => 'rtorrent_port_reservations_reconcile',
        'level' => $level,
    ), $result));
    echo ($encoded ?: '{"status":"error","reason":"json_encode_failed"}')."\n";
    return $result['status'] === 'error' ? 1 : 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(pmssRtorrentPortReservationsReconcileMain());
}
