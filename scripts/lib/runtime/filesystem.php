<?php
/**
 * Stable filesystem facade and path/inode primitives for runtime consumers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssDirEnsureExists(string $path, int $mode = 0755): bool { return $path !== '' && !pmssFilesystemPathHasNulByte($path) && (is_dir($path) || @mkdir($path, $mode, true) || is_dir($path)); }

/**
 * Read directory children, preserving scandir ordering, keys, and failure state.
 *
 * Only dot entries are removed; callers retain path and symlink policy.
 * @return array<int, string>|false
 */
function pmssDirectoryEntriesRead(string $path)
{
    // Invalid paths must keep the scan-failure result across PHP versions.
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return false;
    $entries = @scandir($path);
    return is_array($entries) ? array_diff($entries, ['.', '..']) : false;
}

// NUL bytes make PHP filesystem calls version-dependent; reject them at the
// runtime boundary and keep callers on the existing fail-soft path.
function pmssFilesystemPathHasNulByte(string $path): bool { return strpos($path, "\0") !== false; }
/** A write succeeds only when every payload byte reaches the file. */
function pmssFileWriteComplete(string $path, string $content, int $flags = 0, ?callable $writer = null): bool { return !pmssFilesystemPathHasNulByte($path) && ($writer ? $writer($path, $content) : @file_put_contents($path, $content, $flags)) === strlen($content); }
/** Treat a dangling symlink as an occupied path, unlike file_exists(). */
function pmssPathExistsOrLink(string $path): bool { return file_exists($path) || is_link($path); }
function pmssRegularFilePathIsReadable(string $path): bool { return $path !== '' && !pmssFilesystemPathHasNulByte($path) && is_file($path) && !is_link($path); }

/** Lock and other write targets must be plain files; refuse symlinks and device paths. */
function pmssLockFilePathIsSafe(string $path): bool { return $path !== '' && !pmssFilesystemPathHasNulByte($path) && !is_link($path) && (!file_exists($path) || is_file($path)); }

/** Confirm an opened stream still points at a path or supplied path snapshot. */
function pmssLockFileHandleMatchesPath($handle, string $path, ?array $pathStat = null, ?array &$handleStat = null): bool
{
    $handleStat = null; $validateLockPath = $pathStat === null;
    if (!pmssStreamHandleIsOpen($handle) || ($validateLockPath && !pmssLockFilePathIsSafe($path))) return false;
    $observedHandleStat = @fstat($handle); $pathStat = $validateLockPath ? @stat($path) : $pathStat;
    $handleStat = is_array($observedHandleStat) ? $observedHandleStat : null;
    if ($handleStat === null || !is_array($pathStat)) return false;
    if (!$validateLockPath) return ($handleStat['dev'] ?? null) === ($pathStat['dev'] ?? null)
        && ($handleStat['ino'] ?? null) === ($pathStat['ino'] ?? null);
    return isset($handleStat['dev'], $handleStat['ino'], $pathStat['dev'], $pathStat['ino'])
        && (int) $handleStat['dev'] === (int) $pathStat['dev'] && (int) $handleStat['ino'] === (int) $pathStat['ino'];
}

require_once __DIR__.'/filesystemTemp.php';
require_once __DIR__.'/filesystemStream.php';
require_once __DIR__.'/filesystemRead.php';
require_once __DIR__.'/filesystemHost.php';
