<?php
/**
 * rTorrent watchdog state marker helpers.
 *
 * Keeps marker-file naming, stale-condition timing, and restart-grace state out
 * of process control code while preserving the legacy function names.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../runtime.php';

/**
 * Validate a watchdog marker path before writing or removing it.
 */
function rtorrentProcessStateFilePathIsSafe(string $stateFile): bool
{
    return pmssPathTargetIsSafe($stateFile, false, true, false);
}

/**
 * Write a watchdog marker after validating the target path.
 */
function rtorrentProcessWriteStateFile(string $stateFile, string $payload): bool
{
    return rtorrentProcessStateFilePathIsSafe($stateFile)
        && @file_put_contents($stateFile, $payload, LOCK_EX) !== false;
}

/**
 * Track first-seen time for a stale condition.
 *
 * @return array{action:string,age:int}
 */
function rtorrentProcessCheckStaleState(string $stateFile, int $gracePeriod): array
{
    if (!rtorrentProcessStateFilePathIsSafe($stateFile)) {
        return ['action' => 'record', 'age' => 0];
    }

    $now = time();
    $firstSeen = pmssReadRegularFileInt($stateFile);
    if ($firstSeen <= 0) {
        rtorrentProcessWriteStateFile($stateFile, (string) $now);
        return ['action' => 'record', 'age' => 0];
    }

    $age = $now - $firstSeen;
    return ['action' => $age < $gracePeriod ? 'wait' : 'stale', 'age' => $age];
}

/**
 * Track consecutive failed attempts with a counter marker.
 *
 * @return array{action:string,count:int}
 */
function rtorrentProcessCheckFailureCountState(string $stateFile, int $failureThreshold): array
{
    $failureThreshold = max(1, $failureThreshold);
    if (!rtorrentProcessStateFilePathIsSafe($stateFile)) {
        return ['action' => 'record', 'count' => 1];
    }

    $count = max(0, pmssReadRegularFileInt($stateFile)) + 1;
    rtorrentProcessWriteStateFile($stateFile, (string) $count);

    return [
        'action' => $count >= $failureThreshold ? 'stale' : ($count === 1 ? 'record' : 'wait'),
        'count' => $count,
    ];
}

/**
 * Return all checkRtorrent marker paths for one user.
 */
function rtorrentProcessWatchdogStatePaths(string $stateDir, string $user): array
{
    $prefix = rtrim($stateDir, '/').'/checkRtorrent-';
    return [
        'missing' => $prefix.'missing-'.$user.'.ts',
        'unresponsive' => $prefix.'unresponsive-'.$user.'.ts',
        'acceptQueueWedge' => $prefix.'accept-queue-'.$user.'.count',
        'startMarker' => $prefix.'started-'.$user.'.ts',
        'startFailure' => $prefix.'start-failure-'.$user.'.count',
        'sessionReset' => $prefix.'session-reset-'.$user.'.ts',
        'escalation' => $prefix.'escalated-'.$user.'.flag',
    ];
}

/**
 * Clear marker files whose underlying condition has resolved.
 */
function rtorrentProcessClearResolvedWatchdogState(array $state, bool $rtorrentPresent, bool $executorPresent): void
{
    $clear = [];
    if ($rtorrentPresent || !$executorPresent) {
        $clear[] = 'missing';
    }
    if (!$rtorrentPresent) {
        $clear[] = 'acceptQueueWedge';
    }
    if ($rtorrentPresent || $executorPresent) {
        $clear = array_merge($clear, ['startMarker', 'startFailure', 'sessionReset', 'escalation']);
    }

    foreach (array_unique($clear) as $key) {
        if (isset($state[$key])) {
            rtorrentProcessClearStaleState((string) $state[$key]);
        }
    }
}

/**
 * Return the SCGI grace period, extending it after recent restart markers.
 *
 * @return array{grace:int,restartAge:int}
 */
function rtorrentProcessUnresponsiveGraceState(string $restartMarker, int $baseGrace, ?int $now = null): array
{
    $now = $now ?? time();
    $restartTs = is_file($restartMarker) ? (int) trim((string) @file_get_contents($restartMarker)) : 0;
    $restartAge = $restartTs > 0 ? max(0, $now - $restartTs) : 0;
    $grace = $baseGrace;
    if ($restartAge > 0 && $restartAge < 7200) {
        $grace = max($baseGrace, 600);
    } elseif ($restartAge > 0 && $restartAge < 14400) {
        $grace = max($baseGrace, 1200);
    }

    return ['grace' => $grace, 'restartAge' => $restartAge];
}

/**
 * Remove a validated watchdog marker if it exists.
 */
function rtorrentProcessClearStaleState(string $stateFile): void
{
    if (rtorrentProcessStateFilePathIsSafe($stateFile) && is_file($stateFile)) {
        @unlink($stateFile);
    }
}
