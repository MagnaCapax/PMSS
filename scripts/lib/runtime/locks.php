<?php
/**
 * Runtime lock helpers shared by CLI tools and cron entrypoints.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Lock files must be plain files; refuse symlinks and device paths. */
function pmssLockFilePathIsSafe(string $path): bool
{
    if ($path === '' || pmssFilesystemPathHasNulByte($path) || is_link($path)) return false;
    return !file_exists($path) || is_file($path);
}

function pmssLockFileAcquire(string $path, bool $nonBlocking = false, string $mode = 'c', bool $createParentDir = false, bool $closeOnBusy = true, ?bool &$busy = null)
{
    $busy = false;
    if (!pmssLockFilePathIsSafe($path)) return false;
    if ($createParentDir && !pmssDirEnsureExists(dirname($path), 0755)) return false;
    if (($handle = @fopen($path, $mode)) === false) return false;
    if (!pmssLockFilePathIsSafe($path)) { @fclose($handle); return false; }
    if (!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
        $busy = true;
        if ($closeOnBusy) { @fclose($handle); return false; }
    }
    return $handle;
}

/** Record the current process id in an acquired lock handle. */
function pmssLockHandleWritePid($handle): bool
{
    if (!is_resource($handle)) return false;
    $pid = (string) getmypid();
    if (!@ftruncate($handle, 0) || !@rewind($handle)) return false;
    return @fwrite($handle, $pid) === strlen($pid) && @fflush($handle);
}

function pmssRuntimeLockBasename(string $basename): string
{
    $basename = ltrim($basename, '/');
    if ($basename === '' || $basename === '.' || $basename === '..' || strpos($basename, '/') !== false || preg_match('/[\r\n\0]/', $basename) === 1) {
        throw new RuntimeException('Unsafe runtime lock basename');
    }
    return $basename;
}

function pmssRuntimeLockPath(string $basename): string { return (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/'.pmssRuntimeLockBasename($basename); }
function pmssLockHandleRelease($handle, bool $unlock = true): void { $unlock && @flock($handle, LOCK_UN); @fclose($handle); }
