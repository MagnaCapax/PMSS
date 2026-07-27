#!/usr/bin/env php
<?php
/**
 * Reclaim a terminated user home that was already renamed aside.
 *
 * This utility is intentionally narrow: it accepts only PMSS-generated
 * /home/.terminating-* paths and never operates on active /home/<user> names.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/user/homeReclaimWorker.php';

/** Execute the asynchronous home reclaim worker or its periodic reaper mode. */
function pmssUserHomeReclaimMain(array $argv): int
{
    pmssRequireCli();
    requireRoot();

    $usage = "Usage: userHomeReclaim.php --confirm /home/.terminating-USER-YYYYMMDDHHMMSS-PID\n"
        ."       userHomeReclaim.php --sweep\n";
    $confirm = false;
    $sweep = false;
    $targetPath = '';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--confirm') {
            $confirm = true;
            continue;
        }
        if ($arg === '--sweep') {
            $sweep = true;
            continue;
        }
        if ($targetPath === '') {
            $targetPath = $arg;
            continue;
        }
        return pmssCliReturnWithStderr($usage, 2);
    }

    if ($sweep) {
        if ($confirm || $targetPath !== '') {
            return pmssCliReturnWithStderr($usage, 2);
        }
        return pmssUserHomeReclaimSweepMain();
    }

    if (!$confirm || $targetPath === '') {
        return pmssCliReturnWithStderr($usage, 2);
    }

    return pmssUserHomeReclaimRunTarget($targetPath);
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserHomeReclaimMain');
