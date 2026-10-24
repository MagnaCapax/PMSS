<?php
/** Quota file diagnostics and guarded stale-check cleanup. @license GPL-3.0-only */
require_once __DIR__.'/../runtime.php';

/**
 * Warn if quota state files under the mount point have unexpected names.
 *
 * ext4 journaled quotas expect `aquota.user` and `aquota.group`. Garbage
 * names have been observed in the fleet and usually indicate manual edits
 * or interrupted quota tooling.
 */
function pmssWarnUnexpectedQuotaFiles(string $mountPoint, ?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if ($mountPoint === '' || !is_dir($mountPoint) || ($entries = pmssDirectoryEntriesRead($mountPoint)) === false) {
        return;
    }

    $unexpected = [];
    foreach ($entries as $entry) {
        if (strpos($entry, 'aquota.') !== 0 || $entry === 'aquota.user' || $entry === 'aquota.group') {
            continue;
        }
        $unexpected[] = pmssQuotaEscapePathForLog($entry);
    }

    if ($unexpected === []) {
        return;
    }

    sort($unexpected, SORT_STRING);
    $log('[WARN] Unexpected quota files under '.$mountPoint.': '.implode(', ', $unexpected));
}

/** Escape filesystem paths before writing them to operator-facing logs. */
function pmssQuotaEscapePathForLog(string $path): string
{
    return addcslashes($path, "\0..\37\177..\377");
}

/**
 * Remove stale quota check files after validating the mount point boundary.
 *
 * quotacheck may leave temporary `aquota*new` files behind after an interrupted
 * run. Only regular files directly under the mount point are eligible here.
 * Reject control bytes before normalization can hide a malformed target.
 */
function pmssRemoveStaleQuotaCheckFiles(string $mountPoint = '/home', ?callable $logger = null): int
{
    $log = $logger ?: 'logMessage';
    if (preg_match('/[\r\n\0]/', $mountPoint) === 1) {
        $log('[quotaFix] WARNING: refusing unsafe quota cleanup path: '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }
    $mountPoint = rtrim(trim($mountPoint), '/');
    if ($mountPoint === '') {
        $mountPoint = '/';
    }

    if ($mountPoint[0] !== '/') {
        $log('[quotaFix] WARNING: refusing unsafe quota cleanup path: '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }

    $realMountPoint = realpath($mountPoint);
    if ($realMountPoint === false || !is_dir($mountPoint) || is_link($mountPoint) || $realMountPoint !== $mountPoint) {
        $log('[quotaFix] WARNING: refusing quota cleanup outside stable mount point: '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }

    $staleFiles = glob($mountPoint.'/aquota*new');
    if ($staleFiles === false) {
        $log('[quotaFix] WARNING: unable to scan stale quota check files under '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }

    if ($staleFiles === []) {
        $log('[quotaFix] No stale files found');
        return 0;
    }

    $removed = 0;
    foreach ($staleFiles as $stale) {
        if (dirname($stale) !== $mountPoint || !pmssRegularFilePathIsReadable($stale)) {
            $log('[quotaFix] WARNING: skipped unsafe stale quota path: '.pmssQuotaEscapePathForLog($stale));
            continue;
        }

        if (!@unlink($stale)) {
            $log('[quotaFix] WARNING: failed to remove stale file: '.pmssQuotaEscapePathForLog($stale));
            continue;
        }

        $removed++;
        $log('[quotaFix] Removed stale file: '.pmssQuotaEscapePathForLog($stale));
    }

    return $removed;
}
