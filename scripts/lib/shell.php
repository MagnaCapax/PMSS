<?php
/**
 * Shared shell command helpers for standalone PMSS scripts.
 *
 * Centralises passthru()-based command execution so scripts do not carry their
 * own generic run() helpers with colliding names or drifting behaviour.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Execute a shell command and return its exit status.
 *
 * The command inherits the current stdout/stderr streams via passthru(). When
 * the command exits non-zero, this helper can emit a consistent stderr line so
 * callers can decide whether the failure is fatal.
 *
 * @param string $cmd Shell command to execute.
 * @param bool $logFailure Whether to log a standard stderr message on failure.
 *
 * @return int Exit status reported by passthru().
 */
function pmssRun(string $cmd, bool $logFailure = true): int
{
    $exitCode = 0;
    passthru($cmd, $exitCode);
    if ($exitCode !== 0 && $logFailure) {
        fwrite(STDERR, "Command failed (rc={$exitCode}): {$cmd}\n");
    }
    return $exitCode;
}

/**
 * Execute a shell command and abort the current script on failure.
 *
 * This preserves the historical recreateUser.php contract where command
 * failures stop execution immediately after the standard failure line.
 *
 * @param string $cmd Shell command to execute.
 * @param bool $logFailure Whether to log a standard stderr message on failure.
 *
 * @return void Exits with the command status when the command fails.
 */
function pmssRunOrExit(string $cmd, bool $logFailure = true): void
{
    if (($exitCode = pmssRun($cmd, $logFailure)) !== 0) {
        exit($exitCode);
    }
}
