#!/usr/bin/env php
<?php
/**
 * Restore cached ruTorrent channel rates after rTorrent starts.
 *
 * This runs as the customer UID and loads only the customer's ruTorrent tree.
 */

$customConfig = @file_get_contents(__DIR__.'/.rtorrent.rc.custom');
if (is_string($customConfig)
    && preg_match('/^\s*(?:throttle_(?:up|down)|throttle\.(?:up|down))\s*=/m', $customConfig) === 1) {
    // Customer-defined named throttles remain authoritative.
    exit(0);
}

$user = '';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $account = @posix_getpwuid(posix_geteuid());
    if (is_array($account) && isset($account['name'])) {
        $user = (string) $account['name'];
    }
}
if ($user === '') {
    $user = (string) get_current_user();
}
if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $user) !== 1) {
    exit(1);
}

$_SERVER['REMOTE_USER'] = $user;
$rutorrentRoot = __DIR__.'/www/rutorrent';
$phpRoot = $rutorrentRoot.'/php';
$throttlePath = $rutorrentRoot.'/plugins/throttle/throttle.php';
if (!is_dir($phpRoot) || !is_file($throttlePath) || !@chdir($phpRoot)) {
    exit(1);
}

try {
    require_once $throttlePath;
    if (!class_exists('rThrottle', false)) {
        exit(1);
    }
    $throttle = rThrottle::load();
    exit(is_object($throttle) && method_exists($throttle, 'obtain') && $throttle->obtain() ? 0 : 1);
} catch (Throwable $throwable) {
    exit(1);
}
