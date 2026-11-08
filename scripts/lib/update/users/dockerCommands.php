<?php
/** Account command validation and logging; loaded by docker.php. @license GPL-3.0-only */

/** Keep shell-facing user maintenance helpers behind the username policy. */
function pmssUserMaintenanceUsernameAllowed(string $user, string $context): bool
{
    if (pmssValidateUsername($user)) {
        return true;
    }

    $safeUser = pmssLogSafeLabel($user);
    logMessage(sprintf('[WARN] %s refused invalid username: %s', $context, $safeUser));
    return false;
}

/** Run a shell command and copy stdout/stderr/rc into the user's log. */
function pmssRunAndLog(string $user, string $label, string $command, bool $asUser = false): int
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssRunAndLog')) {
        return 127;
    }

    $inner = $asUser ? pmssBuildUserShellCommand($user, $command) : $command;
    pmssUserLog($user, "[CMD] {$label}: {$command}");
    $result = pmssCommandCapture($inner, 0, true, 'Failed to start process', 127);
    if ((int) $result['rc'] === 127 && $result['stdout'] === '' && $result['stderr'] === 'Failed to start process') {
        pmssUserLog($user, "[ERR] Failed to start process for: {$label}");
        return 127;
    }

    foreach (['stdout', 'stderr'] as $stream) {
        $output = rtrim((string) $result[$stream]);
        if ($output !== '') pmssUserLog($user, $output);
    }
    pmssUserLog($user, sprintf('[RC] %s -> %d', $label, (int) $result['rc']));
    return (int) $result['rc'];
}
