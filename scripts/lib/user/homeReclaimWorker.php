<?php
/**
 * Shared worker and sweep operations for terminated-home reclaim.
 *
 * The CLI utility owns argument parsing; this module keeps the deletion path
 * identical for termination-triggered work and periodic retries.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once __DIR__.'/homeReclaimRetry.php';

/** Refuse reclaim when the target no longer satisfies its safety contract. */
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

/** Reclaim one target while sharing the worker/reaper safety and lock path. */
function pmssUserHomeReclaimRunTarget(string $targetPath): int
{
    $username = pmssUserHomeReclaimPathUsername($targetPath);
    if ($username === null || !pmssUserHomeReclaimPathIsSafe($targetPath)) {
        return pmssUserHomeReclaimRefuseUnsafePath($username, $targetPath, 'home_reclaim_unsafe');
    }

    $lock = pmssUserHomeReclaimAcquireLock($targetPath);
    if ($lock === false) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'home_reclaim_locked', $username, 'SKIP', 'Reclaim already running', array('path' => $targetPath));
        return 0;
    }
    if ($lock === null) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'home_reclaim_lock', $username, 'ERR', 'Unable to acquire reclaim lock', array('path' => $targetPath));
        return 1;
    }

    try {
        clearstatcache(true, $targetPath);
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

        $reclaimRc = pmssUserLifecycleRunSteps('terminate', $username, array(
            array('home_reclaim_delete_contents', pmssBuildCommand('find', array($targetPath, '-xdev', '-depth', '-mindepth', '1', '-delete'))),
            array('home_reclaim_remove_root', pmssBuildCommand('rmdir', array('--', $targetPath))),
        ), false);

        $ok = ($reclaimRc['home_reclaim_delete_contents'] ?? 1) === 0
            && ($reclaimRc['home_reclaim_remove_root'] ?? 1) === 0;
        pmssUserLifecycleContextLogStatusMessage(
            'terminate',
            'home_reclaim_end',
            $username,
            $ok ? 'OK' : 'ERR',
            $ok ? 'Reclaimed terminated home' : 'Terminated home reclaim incomplete',
            array('path' => $targetPath)
        );

        return $ok ? 0 : 1;
    } finally {
        pmssUserHomeReclaimReleaseLock($lock);
    }
}

/** Return due reclaim paths from the top level of the mounted /home. */
function pmssUserHomeReclaimSweepTargets(?int $now = null): array
{
    $entries = @scandir('/home');
    if (!is_array($entries)) {
        return array();
    }

    $now = $now ?? time();
    $targets = array();
    foreach ($entries as $entry) {
        $targetPath = '/home/'.$entry;
        if (pmssUserHomeReclaimPathIsDue($targetPath, $now)) {
            $targets[] = $targetPath;
        }
    }

    sort($targets, SORT_STRING);
    return $targets;
}

/** Reclaim all old safe targets and leave a concise cron-visible summary. */
function pmssUserHomeReclaimSweepMain(): int
{
    $targets = pmssUserHomeReclaimSweepTargets();
    $failed = 0;
    foreach ($targets as $targetPath) {
        if (pmssUserHomeReclaimRunTarget($targetPath) !== 0) {
            $failed++;
        }
    }

    echo date('c').': user home reclaim sweep targets='.count($targets).' failed='.$failed."\n";
    return $failed === 0 ? 0 : 1;
}
