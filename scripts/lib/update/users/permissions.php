<?php
/**
 * @domain user permissions — ownership and permission refresh
 *
 * Permission refresh helpers for update-step2 user maintenance.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Refresh user permissions and recover canonical rtorrent custom config.
 */
function pmssUserRefreshPermissions(array $ctx): void
{
    $user = $ctx['user'];
    $home = $ctx['home'];

    $timeoutRaw = (string) getenv('PMSS_USER_PERMISSIONS_TIMEOUT');
    $timeoutSeconds = (ctype_digit($timeoutRaw) && (int) $timeoutRaw > 0) ? (int) $timeoutRaw : 900;
    $previousTimeout = getenv('PMSS_COMMAND_TIMEOUT');
    $permissionsCommand = pmssBuildCommand('/scripts/util/userPermissions.php', [$user]);
    $ionicePath = is_executable('/usr/bin/ionice') ? '/usr/bin/ionice' : (is_executable('/bin/ionice') ? '/bin/ionice' : '');
    if ($ionicePath !== '') {
        $permissionsCommand = pmssBuildCommand($ionicePath, ['-c3', '/scripts/util/userPermissions.php', $user]);
    }

    putenv('PMSS_COMMAND_TIMEOUT='.(string) $timeoutSeconds);
    try {
        $rc = runUserStep($user, 'Refreshing user permissions', $permissionsCommand);
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
    $rcCustomContent = (!is_file($rcCustomPath) || is_link($rcCustomPath)) ? false : @file_get_contents($rcCustomPath);
    if (is_string($rcCustomContent)
        && in_array(sha1($rcCustomContent), ['dcf21704d49910d1670b3fdd04b37e640b755889', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
        $skelRcCustomPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.rtorrent.rc.custom';
        runUserStep(
            $user,
            'Updating .rtorrent.rc.custom from skeleton',
            sprintf('cp %s %s/', $skelRcCustomPath === '/etc/skel/.rtorrent.rc.custom' ? $skelRcCustomPath : escapeshellarg($skelRcCustomPath), escapeshellarg($home))
        );
    }
}
