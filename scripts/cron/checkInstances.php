#!/usr/bin/env php
<?php
/**
 * Back-compat wrapper.
 *
 * Historically this watchdog was named checkInstances.php. It has been renamed
 * to checkRtorrent.php to better reflect its responsibility. Keep this entry
 * point so older cron templates and custom automation do not break.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
if (pmssLockFileAcquire((is_dir('/run/lock') ? '/run/lock' : '/tmp').'/pmss-checkInstances.lock', true) === false) {
    exit(0);
}

$target = __DIR__.'/checkRtorrent.php';
if (!is_file($target)) {
    fwrite(STDERR, date('c')." ERROR: {$target} missing; cannot run rTorrent watchdog\n");
    exit(1);
}

$args = isset($argv) ? $argv : (isset($_SERVER['argv']) ? $_SERVER['argv'] : []);
array_shift($args);

$cmd = escapeshellarg($target);
if (!empty($args)) {
    $cmd .= ' '.implode(' ', array_map('escapeshellarg', $args));
}

$rc = 0;
passthru($cmd, $rc);
exit($rc);
