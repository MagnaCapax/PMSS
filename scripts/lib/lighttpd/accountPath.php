<?php
/**
 * Account-owned path operations launched under the account identity.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../log.php';

/**
 * Root launches account work without inherited env or a controlling TTY. Standard input stays the caller's
 * pipe because pmssAccountPathRun streams file content over it, so neither </dev/null nor --pty is used;
 * setsid leaves the account process without a controlling terminal.
 */
function pmssAccountPathShellCommand(int $effectiveUid, int $accountUid, string $username, string $command): string
{
    return $effectiveUid === $accountUid
        ? 'sh -c '.escapeshellarg($command)
        : 'env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin setsid runuser -u '.escapeshellarg($username)
            .' -- sh -c '.escapeshellarg($command);
}

/** Resolve a real account; development fixtures may run as their own non-root UID. */
function pmssAccountPathIdentity(string $username, string $home)
{
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (is_array($account) && isset($account['uid'])) return $account;
    $uid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
    if ($uid > 0 && getenv('PMSS_TEST_MODE') === '1'
        && preg_match('/^[a-z][a-z0-9]{0,7}$/D', $username) === 1
        && @fileowner($home) === $uid) {
        return ['uid' => $uid, 'gid' => @posix_getegid(), 'name' => $username];
    }
    return false;
}

/**
 * Run one command for paths within an account home.
 *
 * The caller supplies the complete shell command and must use this only for
 * account-owned state. Managed root-owned entries use the managed writer.
 * Paths are checked before launch; the account identity limits the effect of
 * changes made by the account between validation and command execution.
 * Input is streamed over stdin, never placed in the command string.
 * Optional failure text distinguishes pre-launch rejection from child failure;
 * callers may log it, but must never parse account-influenced stderr as data.
 */
function pmssAccountPathRun(string $username, string $home, array $paths, string $command, ?string $input = null, int $timeoutSec = 0, ?bool &$timedOut = null, ?string &$failure = null): bool
{
    $timedOut = false;
    $failure = 'stage=validation rc=not-started';
    $account = pmssAccountPathIdentity($username, $home);
    $effectiveUid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
    if (!is_array($account) || !isset($account['uid']) || $username === '' || $command === ''
        || strpos($command, "\0") !== false || $paths === []
        || ($effectiveUid !== 0 && $effectiveUid !== $account['uid'])
        || !pmssPathTargetIsSafe($home, true) || !is_dir($home)
        || @fileowner($home) !== $account['uid']) {
        return false;
    }
    foreach ($paths as $path) {
        // The home itself was checked above; every other path must lie inside it.
        if (!is_string($path) || !pmssPathSegmentsAreSafe($path, false, true)
            || (rtrim($path, '/') !== rtrim($home, '/') && !pmssPathWithinResolvedRoot($path, $home))) {
            return false;
        }
    }

    $shellCommand = pmssAccountPathShellCommand($effectiveUid, $account['uid'], $username, $command);
    if ($timeoutSec > 0) {
        require_once __DIR__.'/../runtime.php';
        $shellCommand = pmssCommandProcessGroupWrap($shellCommand, $timeoutSec);
    }
    // A file cannot fill a pipe while the parent is still streaming stdin.
    $failure = 'stage=stderr rc=not-started';
    $stderr = @tmpfile();
    if (!is_resource($stderr)) return false;
    try {
        // Keep only the private open descriptor, even if a child outlives its parent.
        if (!@unlink(stream_get_meta_data($stderr)['uri'])) return false;
        $failure = 'stage=launch rc=not-started';
        // Start in /: the caller's directory (often /root) is unreadable to the account, and
        // GNU find exits 1 when it cannot return there.
        $process = @proc_open($shellCommand, [
            0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => $stderr,
        ], $pipes, '/');
        if (!is_resource($process)) return false;

        $written = 0;
        $length = strlen($input ?? '');
        $startedAt = microtime(true);
        if ($timeoutSec > 0 && !@stream_set_blocking($pipes[0], false)) {
            @fclose($pipes[0]);
            pmssCommandTimeoutTerminate($process);
            $failure = 'stage=stdin rc='.@proc_close($process);
            return false;
        }
        while ($written < $length) {
            if ($timeoutSec > 0 && microtime(true) - $startedAt >= $timeoutSec) { $timedOut = true; break; }
            if ($timeoutSec > 0) {
                $read = []; $write = [$pipes[0]]; $except = [];
                if (@stream_select($read, $write, $except, 0, 200000) !== 1) continue;
            }
            $count = @fwrite($pipes[0], substr($input, $written));
            if (!is_int($count) || ($count < 1 && $timeoutSec === 0)) break;
            if ($count < 1) continue;
            $written += $count;
        }
        @fclose($pipes[0]);
        $state = null;
        if ($timeoutSec > 0 && !$timedOut) {
            while (true) {
                $state = @proc_get_status($process);
                if (!is_array($state)) { $timedOut = true; break; }
                if (empty($state['running'])) break;
                if (microtime(true) - $startedAt >= $timeoutSec) { $timedOut = true; break; }
                usleep(100000);
            }
        }
        $status = $timedOut ? pmssCommandTimeoutClose($process, $shellCommand, $timeoutSec, $startedAt)
            : ($timeoutSec > 0 ? pmssProcessCloseExitCode($process, $state) : @proc_close($process));
        $ok = !$timedOut && $written === $length && $status === 0;
        $failure = $ok ? '' : 'stage='.($timedOut ? 'timeout' : ($written === $length ? 'child' : 'stdin')).' rc='.$status;
        return $ok;
    } finally {
        // Read only the first 300 bytes; never retain or log the full account tree errors.
        $excerpt = @rewind($stderr) ? trim(pmssLogControlCharactersReplace((string) @fread($stderr, 300))) : '';
        @fclose($stderr);
        if ($failure !== '' && $excerpt !== '') $failure .= ' stderr='.$excerpt;
    }
}
