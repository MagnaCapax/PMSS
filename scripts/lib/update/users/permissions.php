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

    $timeoutSeconds = pmssEnvReadDigits('PMSS_USER_PERMISSIONS_TIMEOUT') ?: 900;
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
        pmssUserLog($user, sprintf('[WARN] userPermissions timed out after %ds', $timeoutSeconds));
        throw new \RuntimeException(sprintf('userPermissions timeout after %ds', $timeoutSeconds));
    }
    if ($rc !== 0) {
        pmssUserLog($user, sprintf('[WARN] userPermissions returned rc=%d', $rc));
    }

    $rcCustomPath = "{$home}/.rtorrent.rc.custom";
    $rcCustomContent = pmssReadRegularFileContents($rcCustomPath);
    // Seed only genuinely absent paths; retain every existing-file migration guard.
    if ((!file_exists($rcCustomPath) && !is_link($rcCustomPath)) || (is_string($rcCustomContent)
        && in_array(sha1($rcCustomContent), [
            '81d37b0b09345e3bfa5c2e79e66a3ef055f65905',
            'dcf21704d49910d1670b3fdd04b37e640b755889',
            'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a',
        ], true))) {
        $skelRcCustomPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.rtorrent.rc.custom';
        if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            pmssUserLog($user, '[SKIP] Updating .rtorrent.rc.custom from skeleton (dry run)');
            return;
        }
        $skelContent = @file_get_contents($skelRcCustomPath);
        // Publish an owned temporary inode, never copy/chown through a customer link.
        if (!is_string($skelContent) || !pmssWriteUserFile($rcCustomPath, $skelContent, $user, 0640)) {
            pmssUserLog($user, '[WARN] .rtorrent.rc.custom skeleton refresh failed');
        } else {
            pmssUserLog($user, 'Updated .rtorrent.rc.custom from skeleton');
        }
    }
}
