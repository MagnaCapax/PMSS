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
require_once __DIR__.'/../lib/user/homeReclaim.php';

/**
 * Refuse reclaim when the target no longer satisfies the terminating-home contract.
 */
function pmssUserHomeReclaimRefuseUnsafePath(?string $username, string $targetPath, string $phase): int
{
    fwrite(STDERR, "Refusing unsafe reclaim path: {$targetPath}\n");
    if ($username !== null) {
        pmssUserLifecycleContextLogStatusMessage(
            'terminate',
            $phase,
            $username,
            'ERR',
            'Refusing unsafe reclaim path',
            array('path' => $targetPath)
        );
    }

    return 1;
}

/** Execute the asynchronous home reclaim worker. */
function pmssUserHomeReclaimMain(array $argv): int
{
    pmssRequireCli();
    requireRoot();

    $usage = "Usage: userHomeReclaim.php --confirm /home/.terminating-USER-YYYYMMDDHHMMSS-PID\n";
    $confirm = false;
    $targetPath = '';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--confirm') {
            $confirm = true;
            continue;
        }
        if ($targetPath === '') {
            $targetPath = $arg;
            continue;
        }
        fwrite(STDERR, $usage);
        return 2;
    }

    if (!$confirm || $targetPath === '') {
        fwrite(STDERR, $usage);
        return 2;
    }

    $username = pmssUserHomeReclaimPathUsername($targetPath);
    if ($username === null || !pmssUserHomeReclaimPathIsSafe($targetPath)) {
        return pmssUserHomeReclaimRefuseUnsafePath($username, $targetPath, 'home_reclaim_unsafe');
    }

    if (!file_exists($targetPath)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'home_reclaim_missing', $username, 'OK', 'Reclaim target already absent', array('path' => $targetPath));
        return 0;
    }

    pmssUserLifecycleContextLog('terminate', 'home_reclaim_start', $username, array('status' => 'INFO', 'path' => $targetPath));

    $chattr = pmssCommandPath('chattr');
    if ($chattr !== '') {
        pmssUserLifecycleStep(
            'terminate',
            $username,
            'home_reclaim_clear_immutable',
            pmssBuildCommand('find', array($targetPath, '-xdev', '-exec', $chattr, '-i', '--', '{}', '+')),
            false
        );
    }

    clearstatcache(true, $targetPath);
    if (!pmssUserHomeReclaimPathIsSafe($targetPath)) {
        return pmssUserHomeReclaimRefuseUnsafePath(
            $username,
            $targetPath,
            'home_reclaim_unsafe_after_immutable_clear'
        );
    }

    $deleteRc = pmssUserLifecycleStep(
        'terminate',
        $username,
        'home_reclaim_delete_contents',
        pmssBuildCommand('find', array($targetPath, '-xdev', '-depth', '-mindepth', '1', '-delete')),
        false
    );
    $rootRc = pmssUserLifecycleStep(
        'terminate',
        $username,
        'home_reclaim_remove_root',
        pmssBuildCommand('rmdir', array('--', $targetPath)),
        false
    );

    $ok = $deleteRc === 0 && $rootRc === 0;
    pmssUserLifecycleContextLogStatusMessage(
        'terminate',
        'home_reclaim_end',
        $username,
        $ok ? 'OK' : 'ERR',
        $ok ? 'Reclaimed terminated home' : 'Terminated home reclaim incomplete',
        array('path' => $targetPath)
    );

    return $ok ? 0 : 1;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserHomeReclaimMain');
