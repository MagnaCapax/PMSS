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
function pmssWriteManagedPathFile(string $path, string $contents, string $label, callable $logger): bool
{
    if (!pmssManagedPathIsSafe($path, $label, $logger)) {
        return false;
    }

    if (!pmssAtomicWriteFile($path, $contents, 0644)) {
        $logger('[WARN] Unable to write '.$label.' at '.$path);
        return false;
    }

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
