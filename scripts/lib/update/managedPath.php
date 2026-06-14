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
    int $mode = 0644,
    string $writeFailureMessage = ''
): bool
{
    if (!pmssManagedPathIsSafe($path, $label, $logger)) {
        return false;
    }
    if ($owner === null ? pmssAtomicWriteFile($path, $contents, $mode) : pmssWriteManagedFile($path, $contents, $owner, $group, $mode)) {
        return true;
    }
    $logger($writeFailureMessage !== '' ? $writeFailureMessage : '[WARN] Unable to write '.$label.' at '.$path);
    return false;
}

/**
 * Refresh managed file content only when it changed.
 *
 * Returns true only when a write was completed; unchanged or failed writes
 * return false so callers can gate reload work on real mutations.
 */
function pmssRefreshManagedPathFile(string $path, string $contents, string $label, callable $logger, array $options = []): bool
{
    $existing = @file_get_contents($path);
    if ($existing !== false && $existing === $contents) {
        if (($options['skipMessage'] ?? '') !== '') {
            $logger((string) $options['skipMessage']);
        }
        return false;
    }

    $dir = dirname($path);
    $dirMode = isset($options['directoryMode']) ? (int) $options['directoryMode'] : 0755;
    if (!pmssDirEnsureExists($dir, $dirMode)) {
        $logger((string) ($options['directoryFailureMessage'] ?? '[WARN] Unable to create '.$label.' directory: '.$dir));
        return false;
    }

    $mode = isset($options['mode']) ? (int) $options['mode'] : 0644;
    $owner = array_key_exists('owner', $options) && $options['owner'] !== null ? (string) $options['owner'] : null;
    $group = array_key_exists('group', $options) && $options['group'] !== null ? (string) $options['group'] : null;
    $writeFailure = (string) ($options['writeFailureMessage'] ?? '');
    if (!pmssWriteManagedPathFile($path, $contents, $label, $logger, $owner, $group, $mode, $writeFailure)) {
        return false;
    }

    if (($options['successMessage'] ?? '') !== '') {
        $logger((string) $options['successMessage']);
    }
    return true;
}

/** Build the timestamped backup path, adding a suffix after collisions. */
function pmssManagedPathBackupCandidate(string $path, string $timestamp, int $attempt): string
{
    return $path.'.pmss-backup-'.$timestamp.($attempt === 0 ? '' : '-'.$attempt);
}

/**
 * Create a best-effort backup without following pre-existing target symlinks.
 */
function pmssCreateManagedPathBackup(string $path, string $label, callable $logger, string $timestamp): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $backup = pmssManagedPathBackupCandidate($path, $timestamp, $attempt);
        if (file_exists($backup) || is_link($backup)) {
            continue;
        }
        if (!pmssManagedPathIsSafe($backup, $label.' backup', $logger)) {
            return '';
        }

        $source = @fopen($path, 'rb');
        if (!is_resource($source)) {
            $logger('[WARN] Unable to open '.$label.' for backup at '.$path);
            return '';
        }

        $target = @fopen($backup, 'xb');
        if (!is_resource($target)) {
            @fclose($source);
            if (file_exists($backup) || is_link($backup)) {
                continue;
            }
            $logger('[WARN] Unable to create '.$label.' backup at '.$backup);
            return '';
        }

        $copied = @stream_copy_to_stream($source, $target);
        $targetClosed = @fclose($target);
        @fclose($source);
        if (is_int($copied) && $targetClosed) {
            $sourceMode = @fileperms($path);
            if (is_int($sourceMode)) {
                @chmod($backup, $sourceMode & 0777);
            }
            return $backup;
        }

        @unlink($backup);
        $logger('[WARN] Unable to create '.$label.' backup at '.$backup);
        return '';
    }

    $logger('[WARN] Unable to create '.$label.' backup; timestamped backup paths already exist for '.$path);
    return '';
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
        $backup = pmssCreateManagedPathBackup($path, $label, $logger, date('YmdHis'));
    }
    $contents = implode(PHP_EOL, $contentLines).PHP_EOL;
    if (!pmssWriteManagedPathFile($path, $contents, $label, $logger, $owner, $group, $mode, '[WARN] Failed writing updated '.$path)) return false;
    if ($logSuccess && $backup !== '') $logger('[WARN] Wrote updated '.$path.' (backup '.$backup.')');
    return true;
}

/**
 * Remove a managed file when the target is safe.
 */
function pmssRemoveManagedPathFile(string $path, string $label, callable $logger): bool
{
    if (!pmssManagedPathIsSafe($path, $label, $logger)) return false;
    if (@unlink($path)) return true;
    $logger('[WARN] Unable to remove '.$label.' at '.$path);
    return false;
}
