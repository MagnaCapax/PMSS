<?php
/** Consecutive php-cgi failure markers; failed persistence never permits a restart. @license GPL-3.0-only */

require_once dirname(__DIR__).'/user/identity.php';
require_once __DIR__.'/watchdogSocketRetry.php';

/** Return the per-user marker path for consecutive php-cgi socket failures. */
function pmssLighttpdWatchdogSocketFailureStatePath(string $username, string $runtimeDir = ''): string
{
    if (!pmssValidateUsername($username)) {
        return '';
    }

    $runtimeDir = $runtimeDir === '' ? pmssRuntimeDir() : rtrim($runtimeDir, '/');
    if ($runtimeDir === ''
        || !pmssPathAbsoluteStringIsSafe($runtimeDir)
        || !pmssPathTargetIsSafe($runtimeDir, true)
    ) {
        return '';
    }

    $statePath = $runtimeDir.'/checkLighttpdInstances-socket-'.$username.'.count';
    // Validate the marker itself before either recording or clearing failures.
    return pmssPathTargetIsSafe($statePath, false) ? $statePath : '';
}

/**
 * Record a failed socket probe and say whether the destructive restart gate is met.
 *
 * @return array{action:string,count:int,threshold:int}
 */
function pmssLighttpdWatchdogRecordSocketFailure(string $username, array $options = array()): array
{
    $threshold = max(1, (int) ($options['threshold'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_FAILURE_CYCLES));
    $statePath = pmssLighttpdWatchdogSocketFailureStatePath($username, (string) ($options['runtimeDir'] ?? ''));

    $count = 0;
    if ($statePath !== '' && pmssDirEnsureExists(dirname($statePath), 0755)) {
        $count = max(0, pmssReadRegularFileInt($statePath)) + 1;
        // A partial write must not authorize a destructive restart.
        if (!pmssFileWriteComplete($statePath, (string) $count, LOCK_EX)) $count = 0;
    }

    return array(
        'action' => $count >= $threshold ? 'restart' : 'wait',
        'count' => $count,
        'threshold' => $threshold,
    );
}

/** Clear resolved php-cgi socket failure state for a user. */
function pmssLighttpdWatchdogClearSocketFailure(string $username, array $options = array()): void
{
    $statePath = pmssLighttpdWatchdogSocketFailureStatePath($username, (string) ($options['runtimeDir'] ?? ''));
    if (pmssRegularFilePathIsReadable($statePath)) {
        @unlink($statePath);
    }
}
