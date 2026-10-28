<?php
/** Serialized target validation and immutable-state orchestration. @license GPL-3.0-only */

/** Best-effort immutable toggle for managed root-owned files. */
function pmssManagedFileImmutableSet(string $path, bool $enable): void
{
    if (pmssManagedAccountHomePath($path)) {
        $entry = @lstat($path);
        if (!is_array($entry) || !pmssRootStageImmutableSet($path, $entry, $enable)) {
            error_log("Unable to update managed immutable state: {$path}");
        }
        return;
    }
    if (!pmssUserFilePathIsSafe($path) || !pmssRegularFilePathIsReadable($path)) {
        return;
    }

    static $chattr = null;
    if ($chattr === null) {
        $chattr = '';
        foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
            if (is_executable($candidate)) {
                $chattr = $candidate;
                break;
            }
        }
    }

    $chattr === '' || @exec($chattr.' '.($enable ? '+i' : '-i').' '.escapeshellarg($path).' 2>/dev/null');
}

/**
 * Normalize one serialized-target tuple before any shell-adjacent toggles run.
 *
 * @return array{0:string,1:string,2:int,3:bool}|null
 */
function pmssManagedSerializedTargetNormalize($target): ?array
{
    if (!is_array($target) || count($target) < 4) {
        return null;
    }

    $path = $target[0] ?? null;
    $group = $target[1] ?? null;
    $mode = $target[2] ?? null;
    if (!is_string($path)
        || !is_string($group)
        || (!is_int($mode) && !is_string($mode))
        || !pmssUserFilePathIsSafe($path)
    ) {
        return null;
    }

    // chmod must never receive a mode produced by casting malformed tuple data.
    if (is_string($mode) && preg_match('/^[0-9]+$/D', $mode) !== 1) {
        return null;
    }
    if ((int) $mode < 0 || (int) $mode > 07777) {
        return null;
    }

    return [$path, $group, (int) $mode, (bool) ($target[3] ?? false)];
}

/** Write one serialized payload to each managed target while preserving partial success. */
function pmssManagedSerializedTargetsWrite(string $serialized, array $targets, callable $failureLogger): bool
{
    $allWritesSucceeded = true;
    foreach ($targets as $target) {
        $normalized = pmssManagedSerializedTargetNormalize($target);
        if ($normalized === null) {
            $allWritesSucceeded = false;
            $path = is_array($target) ? ($target[0] ?? null) : null;
            $failureLogger(is_string($path) && strpos($path, "\0") === false ? $path : '(invalid target)');
            continue;
        }

        list($path, $group, $mode, $immutable) = $normalized;
        $failure = '';
        if (pmssManagedAccountHomePath($path)) {
            $ok = pmssRootStageFileWrite($path, $serialized, $group, $mode, $immutable, $failure);
        } else {
            $immutable && pmssManagedFileImmutableSet($path, false);
            try {
                $ok = pmssWriteManagedFile($path, $serialized, 'root', $group, $mode);
            } finally {
                $immutable && pmssManagedFileImmutableSet($path, true);
            }
        }
        if (!$ok) {
            $allWritesSucceeded = false;
            $failureLogger($path, $failure);
        }
    }
    return $allWritesSucceeded;
}
