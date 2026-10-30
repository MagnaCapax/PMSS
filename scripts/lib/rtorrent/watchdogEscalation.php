<?php
/** Persistent-start escalation markers and bounded recovery. @license GPL-3.0-only */
/** Legacy state helpers: @copyright 2010-2025 Magna Capax Finland Oy @license Proprietary */

/**
 * Write the persistent-start-failure escalation marker.
 */
function rtorrentProcessWriteEscalationState(string $stateFile, string $user, int $failureCount, ?int $now = null): bool
{
    $payload = json_encode([
        'timestamp' => $now ?? time(),
        'user' => $user,
        'count' => $failureCount,
    ]);
    // Encoding failure must not truncate an existing escalation marker.
    return $payload !== false && rtorrentProcessWriteStateFile($stateFile, $payload);
}

/**
 * Decide whether an escalated account is due for one bounded recovery start.
 *
 * @return array{action:string,age:int}
 */
function rtorrentProcessEscalationRetryState(string $stateFile, int $retryInterval, ?int $now = null): array
{
    if (!rtorrentProcessStateFilePathIsSafe($stateFile)) {
        return ['action' => 'wait', 'age' => 0];
    }

    // Accept the current JSON marker and the legacy scalar timestamp format.
    $payload = is_file($stateFile) ? @file_get_contents($stateFile) : false;
    $trimmed = is_string($payload) ? trim($payload) : '';
    $decoded = $trimmed !== '' ? pmssJsonDecodeAssoc($payload) : null;
    if (is_array($decoded) && is_numeric($decoded['timestamp'] ?? null)) {
        $timestamp = max(0, (int) $decoded['timestamp']);
    } else {
        $timestamp = is_numeric($trimmed) ? max(0, (int) $trimmed) : 0;
    }
    if ($timestamp <= 0) {
        return ['action' => 'record', 'age' => 0];
    }

    $age = max(0, ($now ?? time()) - $timestamp);

    return ['action' => $age < max(1, $retryInterval) ? 'wait' : 'retry', 'age' => $age];
}

/**
 * Handle an escalated persistent-start failure without retry storms.
 *
 * @param array<string,string> $state
 */
function pmssCheckRtorrentHandleEscalatedStartFailure(
    string $user,
    array $state,
    int $persistentFailureCount,
    int $retryInterval,
    callable $logCallback,
    bool $debug,
    callable $startCallback
): void {
    $retryState = rtorrentProcessEscalationRetryState($state['escalation'], $retryInterval);
    if ($retryState['action'] !== 'retry') {
        if ($retryState['action'] === 'record') {
            rtorrentProcessWriteEscalationState($state['escalation'], $user, $persistentFailureCount);
        }

        pmssCheckRtorrentLogBoth(
            $user,
            'persistent start failure escalated; leaving retry disabled for external monitoring '
                .'until bounded auto-recovery interval elapses '
                .'(age='.$retryState['age'].'s interval='.max(1, $retryInterval).'s)',
            $debug
        );
        return;
    }

    pmssCheckRtorrentLogBoth(
        $user,
        'persistent start failure escalated for '.$retryState['age'].'s; attempting bounded auto-recovery start',
        $debug
    );
    $rc = (int) $startCallback($user, $logCallback, $state['startMarker']);
    if ($rc === 0) {
        rtorrentProcessClearStaleState($state['escalation']);
        pmssCheckRtorrentLogBoth($user, 'bounded auto-recovery start succeeded; clearing escalation', $debug);
        return;
    }

    rtorrentProcessWriteEscalationState($state['escalation'], $user, $persistentFailureCount);
    pmssCheckRtorrentLogBoth($user, "bounded auto-recovery start failed (rc={$rc}); remaining escalated", $debug);
}
