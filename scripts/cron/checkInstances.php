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

$target = __DIR__.'/checkRtorrent.php';
if (!is_file($target)) {
    fwrite(STDERR, date('c')." ERROR: {$target} missing; cannot run rTorrent watchdog\n");
    exit(1);
}

require $target;
