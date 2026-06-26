<?php
/**
 * Per-user service launch helpers.
 *
 * Long-running tenant daemons started from root cron must enter user-UID.slice
 * immediately so the PMSS TasksMax policy contains descendants on cgroup v1/v2.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/identity.php';

/** Resolve the numeric UID used in systemd's user-UID.slice name. */
function pmssUserServiceUid(string $username): ?int
{
    if (!pmssValidateUsername($username)) {
        return null;
    }

    $out = [];
    $rc = 1;
    @exec(pmssBuildCommand('id', ['-u', $username]).' 2>/dev/null', $out, $rc);
    $uid = isset($out[0]) ? trim((string) $out[0]) : '';

    if ($rc !== 0 || preg_match('/^[1-9][0-9]*$/', $uid) !== 1) {
        return null;
    }

    return (int) $uid;
}

/** Return systemd's canonical per-user slice name. */
function pmssUserServiceSliceName(int $uid): string
{
    return 'user-'.$uid.'.slice';
}

/** Build a failing shell command for launch-time validation errors. */
function pmssUserServiceFailureCommand(string $message): string
{
    return pmssBuildCommand('sh', ['-c', 'printf "%s\n" '.escapeshellarg($message).' >&2; exit 1']);
}

/** Build the su argv while preserving the caller's previous login-shell choice. */
function pmssUserServiceSuArgv(string $username, string $innerCommand, bool $loginShell = false, string $shell = ''): array
{
    if ($shell !== '') {
        return ['su', '-s', $shell, '-c', $innerCommand, $username];
    }

    return $loginShell
        ? ['su', '-', $username, '-c', $innerCommand]
        : ['su', $username, '-c', $innerCommand];
}

/**
 * Build a root-run command that starts the user command inside user-UID.slice.
 *
 * The best-effort `systemctl start` materializes the slice before the transient
 * scope is attached; the following `systemd-run --scope` is the placement gate.
 */
function pmssBuildUserServiceShellCommand(string $username, string $innerCommand, bool $loginShell = false, string $shell = ''): string
{
    $uid = pmssUserServiceUid($username);
    if ($uid === null) {
        return pmssUserServiceFailureCommand('Unable to resolve uid for user service launch: '.$username);
    }

    $slice = pmssUserServiceSliceName($uid);
    $ensureSlice = pmssBuildCommand('systemctl', ['start', $slice]).' >/dev/null 2>&1 || true';
    $launch = pmssBuildCommand('systemd-run', array_merge(
        ['--scope', '--collect', '--quiet', '--slice='.$slice, '--'],
        pmssUserServiceSuArgv($username, $innerCommand, $loginShell, $shell)
    ));

    return $ensureSlice.'; '.$launch;
}
