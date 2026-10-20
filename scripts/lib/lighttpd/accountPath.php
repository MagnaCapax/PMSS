<?php
/**
 * Account-owned path operations launched under the account identity.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';

/** Root launches account work without PAM, an inherited TTY, or inherited env. */
function pmssAccountPathShellCommand(int $effectiveUid, int $accountUid, string $username, string $command): string
{
    if (!function_exists('pmssBuildUserShellNoPamCommand')) require_once __DIR__.'/../runtime/environment.php';
    return $effectiveUid === $accountUid
        ? 'sh -c '.escapeshellarg($command)
        : pmssBuildUserShellNoPamCommand($username, $command);
}

/**
 * Run one command for paths within an account home.
 *
 * The caller supplies the complete shell command and must use this only for
 * account-owned state. Managed root-owned entries use the managed writer.
 * Paths are checked before launch; the account identity limits the effect of
 * changes made by the account between validation and command execution.
 * Input is streamed over stdin, never placed in the command string.
 */
function pmssAccountPathRun(string $username, string $home, array $paths, string $command, ?string $input = null): bool
{
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    $effectiveUid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
    if (!is_array($account) || !isset($account['uid']) || $username === '' || $command === ''
        || strpos($command, "\0") !== false || $paths === []
        || ($effectiveUid !== 0 && $effectiveUid !== $account['uid'])
        || !pmssPathTargetIsSafe($home, true) || !is_dir($home)
        || @fileowner($home) !== $account['uid']) {
        return false;
    }
    foreach ($paths as $path) {
        if (!is_string($path) || !pmssPathSegmentsAreSafe($path, false, true)
            || !pmssPathWithinResolvedRoot($path, $home)) {
            return false;
        }
    }

    $shellCommand = pmssAccountPathShellCommand($effectiveUid, $account['uid'], $username, $command);
    $process = @proc_open($shellCommand, [
        0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    if (!is_resource($process)) return false;

    $written = 0;
    $length = strlen($input ?? '');
    while ($written < $length) {
        $count = @fwrite($pipes[0], substr($input, $written));
        if (!is_int($count) || $count < 1) break;
        $written += $count;
    }
    @fclose($pipes[0]);
    $status = @proc_close($process);
    return $written === $length && $status === 0;
}
