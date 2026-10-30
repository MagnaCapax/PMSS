<?php
/**
 * rTorrent watchdog state marker helpers.
 *
 * Keeps marker-file naming, stale-condition timing, and restart-grace state out
 * of process control code while preserving the legacy function names.
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/watchdogEscalation.php';
require_once __DIR__.'/watchdogScgi.php';

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
        // A truncated timestamp or JSON marker is not a successful state write.
        && pmssFileWriteComplete($stateFile, $payload, LOCK_EX);
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
    if (!rtorrentProcessStateFilePathIsSafe($stateFile)) {
        return ['action' => 'wait', 'count' => 0];
    }

    // An unreadable or unwritable counter cannot prove consecutive failures.
    $previousCount = max(0, pmssReadRegularFileInt($stateFile));
    $count = $previousCount === PHP_INT_MAX ? PHP_INT_MAX : $previousCount + 1;
    if (!rtorrentProcessWriteStateFile($stateFile, (string) $count)) {
        return ['action' => 'wait', 'count' => 0];
    }

    return [
        'action' => $count >= max(1, $failureThreshold) ? 'stale' : ($count === 1 ? 'record' : 'wait'),
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
 * Recent-restart marker read by the unresponsive-grace logic.
 *
 * Kept with the other root-owned watchdog state (checkRtorrent ensures /run/pmss) rather
 * than in a shared world-writable directory where any account could create it.
 */
function rtorrentProcessRestartMarkerPath(string $user): string
{
    return '/run/pmss/checkRtorrent-restart-'.$user.'.ts';
}

/**
 * Clear marker files whose underlying condition has resolved.
 */
function rtorrentProcessClearResolvedWatchdogState(array $state, bool $rtorrentPresent, bool $executorPresent): void
{
    // Policy order is also removal order; there is no separate list to build and replay.
    foreach (['missing' => $rtorrentPresent || !$executorPresent, 'acceptQueueWedge' => !$rtorrentPresent]
        + array_fill_keys(['startMarker', 'startFailure', 'sessionReset', 'escalation'], $rtorrentPresent || $executorPresent) as $key => $clear) {
        if ($clear && isset($state[$key])) {
            rtorrentProcessClearStaleState((string) $state[$key]);
        }
    }
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
