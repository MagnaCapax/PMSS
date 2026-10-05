<?php
/** Locked counter-state persistence shared by resource and traffic metering.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/**
 * Acquire a counter state file lock while creating missing files owner-only.
 */
function pmssCounterStateLockAcquire(string $statePath)
{
    if (!pmssPathTargetIsSafe($statePath, false)) {
        return false;
    }

    $previousUmask = umask(0177);
    try {
        return pmssLockFileAcquire($statePath, false, 'c+');
    } finally {
        umask($previousUmask);
    }
}

/**
 * Replace a counter payload on a stream whose lock remains owned by the caller.
 * A failed seek must not truncate the previous state; failed I/O stops the write.
 *
 * @param resource $handle Locked counter-state stream.
 */
function pmssCounterStateWritePayload($handle, string $payload): bool
{
    return pmssLockHandleWritePayload($handle, $payload);
}

/** Persist counter state under lock and return deltas for the selected fields.
 *
 * @param array<string, int> $deltaCeilings
 * @return array{delta: array<string, int>, previous_state: array<string, mixed>, state: array<string, int>}
 */
function pmssCounterStateUpdate(string $statePath, array $state, array $deltaFields, array $deltaCeilings = [], ?callable $stateNext = null): array
{
    $handle = pmssCounterStateLockAcquire($statePath);
    // The lock covers reads and delta calculation as well as persistence.
    try {
        $previousState = $handle !== false ? (pmssJsonDecodeAssoc((string) @stream_get_contents($handle)) ?? []) : [];
        // State transitions that depend on the prior sample must run under this lock.
        if ($stateNext !== null) { $state = $stateNext($previousState, $state); }
        $delta = [];
        foreach ($deltaFields as $field) {
            $currentValue = (int) ($state[$field] ?? 0);
            $previous = $previousState[$field] ?? null;
            $previousValue = is_int($previous) && $previous >= 0 ? $previous : null;
            if (is_string($previous) && ctype_digit($previous)) {
                $previousValue = (int) $previous;
            }
            $candidateDelta = $previousValue !== null && $currentValue >= $previousValue
                ? $currentValue - $previousValue
                : $currentValue;
            $deltaLimit = $deltaCeilings[$field] ?? null;
            $delta[$field] = is_int($deltaLimit) && $deltaLimit >= 0 && $candidateDelta > $deltaLimit
                ? 0
                : $candidateDelta;
        }

        $persisted = false;
        if ($handle !== false && is_string($payload = pmssJsonEncodeSafe($state))) {
            $written = pmssCounterStateWritePayload($handle, $payload);
            $modeSet = @chmod($statePath, 0600);
            $persisted = $written && $modeSet;
            if (!$written || !$modeSet) {
                error_log('[WARN] Unable to persist counter state: '.json_encode($statePath));
            }
        }
    } finally {
        if ($handle !== false) { pmssLockHandleRelease($handle); }
    }
    $result = ['delta' => $delta, 'previous_state' => $previousState, 'state' => $state];
    if ($stateNext !== null) { $result['persisted'] = $persisted; }
    return $result;
}
