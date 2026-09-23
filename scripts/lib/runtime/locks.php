<?php
/**
 * Runtime lock helpers shared by CLI tools and cron entrypoints.
 *
 * Handle operations accept open streams only. Other resources (including
 * stream contexts) follow the existing false/empty/no-op failure paths.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!defined('PMSS_UPDATE_LOCK_FDS_ENV')) {
    define('PMSS_UPDATE_LOCK_FDS_ENV', 'PMSS_UPDATE_LOCK_FDS');
}

function pmssLockFileAcquire(string $path, bool $nonBlocking = false, string $mode = 'c', bool $createParentDir = false, bool $closeOnBusy = true, ?bool &$busy = null)
{
    $busy = false;
    // A truncating open destroys state before flock can report contention.
    // Reject empty/NUL modes too, before either fopen or parent creation.
    if ($mode === '' || strpos($mode, "\0") !== false || $mode[0] === 'w') return false;
    if (!pmssLockFilePathIsSafe($path)) return false;
    if ($createParentDir && !pmssDirEnsureExists(dirname($path), 0755)) return false;
    if (($handle = @fopen($path, $mode)) === false) return false;
    $retained = false;
    try {
        if (!pmssLockFileHandleMatchesPath($handle, $path)) return false;
        if (!@flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
            $busy = true;
            if ($closeOnBusy) return false;
        }
        $retained = true;
        return $handle;
    } finally {
        // Ownership transfers only on return; failures must not retain a descriptor.
        if (!$retained) @fclose($handle);
    }
}

/** Acquire a cron lock; callers must retain the stream until their work ends. */
function pmssCronLockAcquire(string $name, ?callable $onBusy = null, ?callable $acquire = null)
{
    $path = pmssRuntimeLockPath('pmss-'.$name.'.lock');
    $handle = $acquire === null ? pmssLockFileAcquire($path, true) : $acquire($path);
    if ($handle !== false) return $handle;
    // Preserve legacy skip output and status, including silent and alerting jobs.
    $message = $name.' already running; skipping';
    if ($onBusy !== null) exit((int) $onBusy($message));
    fwrite(STDERR, $message."\n");
    exit(0);
}

/** Legacy watchdogs send timestamped skip notices to stdout. */
function pmssCronLockSkipLog(string $message): void { echo date('Y-m-d H:i:s').': '.$message."\n"; }

/**
 * Resolve numeric fd entries that reference an acquired lock handle.
 *
 * @return array<int, int>
 */
function pmssLockHandleFdList($handle, string $fdRoot = '/proc/self/fd'): array
{
    if (!is_resource($handle) || get_resource_type($handle) !== 'stream' || $fdRoot === '' || pmssFilesystemPathHasNulByte($fdRoot) || !is_dir($fdRoot)) return [];
    $handleStat = @fstat($handle);
    if (!is_array($handleStat) || !isset($handleStat['dev'], $handleStat['ino'])) return [];

    $fds = [];
    foreach (scandir($fdRoot) ?: [] as $entry) {
        if (!ctype_digit($entry)) continue;
        $fd = (int) $entry;
        if ($fd <= 2) continue;
        $fdStat = @stat(rtrim($fdRoot, '/').'/'.$entry);
        if (is_array($fdStat)
            && isset($fdStat['dev'], $fdStat['ino'])
            && (int) $fdStat['dev'] === (int) $handleStat['dev']
            && (int) $fdStat['ino'] === (int) $handleStat['ino']) {
            $fds[] = $fd;
        }
    }

    sort($fds);
    return array_values(array_unique($fds));
}

function pmssLockHandleExportChildCloseFds($handle): void
{
    $fds = pmssLockHandleFdList($handle);
    putenv($fds === [] ? PMSS_UPDATE_LOCK_FDS_ENV : PMSS_UPDATE_LOCK_FDS_ENV.'='.implode(',', $fds));
}

function pmssLockChildClosePrefix(): string
{
    $raw = getenv(PMSS_UPDATE_LOCK_FDS_ENV);
    if (!is_string($raw) || trim($raw) === '') return '';

    $closeParts = [];
    foreach (explode(',', $raw) as $fdRaw) {
        $fdRaw = trim($fdRaw);
        if ($fdRaw === '' || !ctype_digit($fdRaw)) continue;
        $fd = (int) $fdRaw;
        if ($fd < 3 || $fd > 1048576) continue;
        $closeParts[$fd] = $fd.'>&-';
    }

    return $closeParts === [] ? '' : 'exec '.implode(' ', $closeParts).'; ';
}

/** Record the current process id in an acquired lock handle. */
function pmssLockHandleWritePid($handle): bool
{
    if (!is_resource($handle) || get_resource_type($handle) !== 'stream') return false;
    $pid = (string) getmypid();
    // Preserve the previous PID when the stream cannot seek to its beginning.
    if (!@rewind($handle) || !@ftruncate($handle, 0)) return false;
    return @fwrite($handle, $pid) === strlen($pid) && @fflush($handle);
}

/** Normalize and validate the basename before resolving its runtime lock path. */
function pmssRuntimeLockPath(string $basename): string
{
    $basename = ltrim($basename, '/');
    if ($basename === '' || $basename === '.' || $basename === '..' || strpos($basename, '/') !== false || preg_match('/[\r\n\0]/', $basename) === 1) {
        throw new RuntimeException('Unsafe runtime lock basename');
    }
    return (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/'.$basename;
}

function pmssLockHandleRelease($handle, bool $unlock = true): void
{
    if (!is_resource($handle) || get_resource_type($handle) !== 'stream') return;
    try {
        $unlock && @flock($handle, LOCK_UN);
    } finally {
        // Closing also releases the lock when an explicit unlock throws.
        @fclose($handle);
    }
}
