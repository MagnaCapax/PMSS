<?php
/**
 * Permission refresh routines for user environments.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/context.php';

/**
 * Resolve the per-user permission refresh timeout.
 */
function pmssUserPermissionsTimeoutSeconds(): int
{
    $timeout = getenv('PMSS_USER_PERMISSIONS_TIMEOUT');
    if ($timeout !== false && ctype_digit($timeout) && (int) $timeout > 0) {
        return (int) $timeout;
    }

    return 900;
}

/**
 * Build the userPermissions command with low-impact I/O scheduling when available.
 */
function pmssUserPermissionsCommand(string $user): string
{
    $scriptPath = '/scripts/util/userPermissions.php';
    foreach (['/usr/bin/ionice', '/bin/ionice'] as $ionicePath) {
        if (is_executable($ionicePath)) {
            return pmssBuildCommand($ionicePath, ['-c3', $scriptPath, $user]);
        }
    }

    return pmssBuildCommand($scriptPath, [$user]);
}

function pmssUserRefreshPermissions(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];

    $timeoutSeconds = pmssUserPermissionsTimeoutSeconds();
    $previousTimeout = getenv('PMSS_COMMAND_TIMEOUT');

    putenv('PMSS_COMMAND_TIMEOUT='.(string) $timeoutSeconds);
    try {
        $rc = runUserStep($user, 'Refreshing user permissions', pmssUserPermissionsCommand($user));
    } finally {
        putenv($previousTimeout === false ? 'PMSS_COMMAND_TIMEOUT' : 'PMSS_COMMAND_TIMEOUT='.$previousTimeout);
    }

    if ($rc === 124) {
        if (function_exists('pmssUserLog')) {
            pmssUserLog($user, sprintf('[WARN] userPermissions timed out after %ds', $timeoutSeconds));
        }
        throw new \RuntimeException(sprintf('userPermissions timeout after %ds', $timeoutSeconds));
    }

    $rcCustomPath = "{$home}/.rtorrent.rc.custom";
    if (file_exists($rcCustomPath)
        && in_array(sha1((string)file_get_contents($rcCustomPath)), ['dcf21704d49910d1670b3fdd04b37e640b755889', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
        $skelRcCustomArg = pmssUserSkelCommandArg('.rtorrent.rc.custom');
        runUserStep(
            $user,
            'Updating .rtorrent.rc.custom from skeleton',
            sprintf('cp %s %s/', $skelRcCustomArg, escapeshellarg($home))
        );
    }
}
