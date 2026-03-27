<?php
/**
 * Shared managed-path safety and mutation helpers for updater modules.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';

/**
 * Validate a managed file target before mutating it.
 */
function pmssManagedPathIsSafe(string $path, string $label, callable $logger): bool
{
    $targetDir = dirname($path);
    if (!is_dir($targetDir) || is_link($targetDir)) {
        $logger('[WARN] Unsafe '.$label.' directory: '.$targetDir);
        return false;
    }

    if (!pmssUserFilePathIsSafe($path)) {
        $logger('[WARN] Unsafe '.$label.' target: '.$path);
        return false;
    }

    return true;
}

/**
 * Atomically replace a managed file when the target is safe.
 */
function pmssWriteManagedPathFile(
    string $path,
    string $contents,
    string $label,
    callable $logger,
    ?string $owner = null,
    ?string $group = null,
    int $mode = 0644
): bool
{
    if (!pmssManagedPathIsSafe($path, $label, $logger)) {
        return false;
    }

    $written = $owner === null
        ? pmssAtomicWriteFile($path, $contents, $mode)
        : pmssWriteManagedFile($path, $contents, $owner, $group, $mode);
    if (!$written) {
        $logger('[WARN] Unable to write '.$label.' at '.$path);
        return false;
    }

    return true;
}

/** Persist a managed file from line content while keeping a timestamped backup. */
function pmssWriteManagedPathFileWithBackup(
    string $path, array $contentLines, string $label, callable $logger, bool $logSuccess = false,
    ?string $owner = null, ?string $group = null, int $mode = 0644
): bool
{
    if (!pmssManagedPathIsSafe($path, $label, $logger)) return false;
    $backup = '';
    if (file_exists($path)) {
        $backup = $path.'.pmss-backup-'.date('YmdHis');
        if (!@copy($path, $backup)) $logger('[WARN] Unable to create '.$label.' backup at '.$backup);
    }
    $contents = implode(PHP_EOL, $contentLines).PHP_EOL;
    $written = $owner === null
        ? pmssAtomicWriteFile($path, $contents, $mode)
        : pmssWriteManagedFile($path, $contents, $owner, $group, $mode);
    if (!$written) { $logger('[WARN] Failed writing updated '.$path); return false; }
    if ($logSuccess && $backup !== '') $logger('[WARN] Wrote updated '.$path.' (backup '.$backup.')');
    return true;
}

/**
 * Remove a managed file when the target is safe.
 */
function pmssRemoveManagedPathFile(string $path, string $label, callable $logger): bool
{
    if (!pmssManagedPathIsSafe($path, $label, $logger)) {
        return false;
    }

    return @unlink($path);
}
