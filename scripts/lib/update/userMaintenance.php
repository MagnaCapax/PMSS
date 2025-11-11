<?php
/**
 * User maintenance helpers for update-step2.
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/users.php';
require_once __DIR__.'/../users.php';

if (!function_exists('pmssListManagedUsers')) {
    /**
     * Return the list of seedbox users tracked by the platform.
     */
    function pmssListManagedUsers(): array
    {
        $users = users::listHomeUsers();
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        return $users;
    }
}

if (!function_exists('pmssUpdateAllUsers')) {
    /**
     * Refresh ruTorrent and skeleton data for every provisioned user.
     */
    function pmssUpdateAllUsers(string $rutorrentIndexSha): void
    {
        foreach (pmssListManagedUsers() as $user) {
            if ($user === '') {
                continue;
            }
            pmssUpdateUserEnvironment($user, ['rutorrent_index_sha' => $rutorrentIndexSha]);
        }
    }
}

if (!function_exists('pmssRefreshSkeletonAndCron')) {
    /**
     * Re-apply skeleton permissions and critical cron/FTP settings.
     */
    function pmssRefreshSkeletonAndCron(): void
    {
        runStep('Refreshing skeleton permissions', '/scripts/util/setupSkelPermissions.php');
        runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');
        runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');
    }
}

if (!function_exists('pmssInstallLogrotatePolicy')) {
    /**
     * Deploy the logrotate policy for update logs when available.
     */
    function pmssInstallLogrotatePolicy(): void
    {
        $template = '/etc/seedbox/config/template.logrotate.pmss';
        if (!file_exists($template)) {
            return;
        }
        runStep('Installing logrotate policy for PMSS update logs', sprintf('cp %s /etc/logrotate.d/pmss-update', escapeshellarg($template)));
        runStep('Setting permissions on PMSS logrotate policy', 'chmod 644 /etc/logrotate.d/pmss-update');
    }
}

if (!function_exists('pmssRestoreUserCrontabs')) {
    /**
     * Restore the default user crontabs from the template.
     */
    function pmssRestoreUserCrontabs(): void
    {
        $command = sprintf(
            'bash -lc %s',
            escapeshellarg('/scripts/listUsers.php | xargs -r -I{} crontab -u {} /etc/seedbox/config/user.crontab.default')
        );
        runStep('Restoring default crontab for all users', $command);
    }
}
