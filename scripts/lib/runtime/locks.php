<?php
/**
 * Runtime lock helpers shared by CLI tools and cron entrypoints.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssLockFileAcquire(string $path, bool $nonBlocking = false, string $mode = 'c', bool $createParentDir = false, bool $closeOnBusy = true, ?bool &$busy = null)
{
    $busy = false;
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return false;
    if ($createParentDir && !pmssDirEnsureExists(dirname($path), 0755)) return false;
    if (($handle = @fopen($path, $mode)) === false) return false;
    if (!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
        $busy = true;
        if ($closeOnBusy) { @fclose($handle); return false; }
    }
    return $handle;
}

function pmssLockHandleWritePid($handle): void { @ftruncate($handle, 0); @rewind($handle); @fwrite($handle, (string) getmypid()); @fflush($handle); }

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
