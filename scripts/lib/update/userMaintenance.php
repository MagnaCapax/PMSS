<?php
/**
 * User maintenance helpers for update-step2.
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/users.php';
require_once __DIR__.'/../users.php';

if (!function_exists('pmssUserLogPath')) {
    /**
     * Return the per-user update log path, ensuring the directory exists.
     */
    function pmssUserLogPath(string $user): string
    {
        $dir = '/var/log/pmss';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $user);
        return rtrim($dir, '/').'/pmss-update-user-'.$safe.'.log';
    }
}

if (!function_exists('pmssUserLog')) {
    /** Append a timestamped line to the user's update log. */
    function pmssUserLog(string $user, string $message): void
    {
        $path = pmssUserLogPath($user);
        $ts   = date('[Y-m-d H:i:s] ');
        @file_put_contents($path, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('pmssRunAndLog')) {
    /**
     * Run a shell command (optionally as the user) and log stdout/stderr + rc to the user's log file.
     */
    function pmssRunAndLog(string $user, string $label, string $command, bool $asUser = false): int
    {
        $inner = $asUser ? sprintf('su %s -c %s', escapeshellarg($user), escapeshellarg($command)) : $command;
        $cmd   = ['/bin/bash', '-lc', $inner];
        $desc  = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        pmssUserLog($user, "[CMD] {$label}: {$command}");
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            pmssUserLog($user, "[ERR] Failed to start process for: {$label}");
            return 127;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $rc = proc_close($proc);
        $out = rtrim((string)$stdout);
        $err = rtrim((string)$stderr);
        if ($out !== '') pmssUserLog($user, $out);
        if ($err !== '') pmssUserLog($user, $err);
        pmssUserLog($user, sprintf('[RC] %s -> %d', $label, (int)$rc));
        return (int)$rc;
    }
}

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
        $list = pmssListManagedUsers();
        $count = count($list);
        logMessage(sprintf('Per-user maintenance: %d user(s) to process', $count));
        foreach ($list as $user) {
            if ($user === '') {
                continue;
            }
            // #TODO Remove this fix block by end of 2027.
            // Legacy fix: Some users have "CPUQuota=85%" explicitly set on their slice due to
            // an old default. This overrides the new, correct global default. Detect and revert it.
            $uinfo = posix_getpwnam($user);
            if ($uinfo && isset($uinfo['uid'])) {
                $slice = 'user-'.(int)$uinfo['uid'].'.slice';
                $qOut = shell_exec('systemctl show '.escapeshellarg($slice).' -p CPUQuota 2>/dev/null');
                if ($qOut && strpos(trim($qOut), 'CPUQuota=85%') !== false) {
                    // Only unset if it matches the exact bad default. User-set 200% etc is preserved.
                    // We use 'set-property ... CPUQuota=' (empty) to remove just this one override
                    // without wiping other custom settings (RAM, weights) like 'revert' would.
                    runStep("Fixing legacy 85% CPUQuota for $user", 'systemctl set-property '.escapeshellarg($slice).' CPUQuota=');
                }
            }

            pmssUpdateUserEnvironment($user, $rutorrentIndexSha);
        }
    }
}

if (!function_exists('pmssEnsureLingerAndDocker')) {
    /**
     * Enable linger, (re)start user@UID systemd instance, and kick rootless Docker for a user.
     * Logs detailed command output to pmss-update-user-<username>.log.
     */
    function pmssEnsureLingerAndDocker(string $user): void
    {
        if (!is_dir('/run/systemd/system')) {
            pmssUserLog($user, '[SKIP] systemd not available on this host');
            return;
        }
        $uid = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
        if ($uid === '' || !ctype_digit($uid)) {
            pmssUserLog($user, '[WARN] Could not resolve UID');
            return;
        }

        pmssUserLog($user, sprintf('== Linger/Docker kick for %s (uid=%s) on host %s ==', $user, $uid, gethostname()));

        // Enable linger to allow user@UID to run without active login.
        pmssRunAndLog($user, 'loginctl enable-linger', 'loginctl enable-linger '.escapeshellarg($user), false);

        // Check and (re)start the per-user systemd instance.
        pmssRunAndLog($user, 'systemctl status user@.service (pre)', 'systemctl --no-pager -l status user@'.$uid.'.service || true', false);
        pmssRunAndLog($user, 'systemctl start user@.service', 'systemctl start user@'.$uid.'.service', false);
        pmssRunAndLog($user, 'systemctl status user@.service (post)', 'systemctl --no-pager -l status user@'.$uid.'.service || true', false);

        // Start rootless Docker for the user and verify.
        pmssRunAndLog($user, 'systemctl --user start docker.service', 'systemctl --user start docker.service', true);
        pmssRunAndLog($user, 'systemctl --user status docker.service', 'systemctl --user --no-pager -l status docker.service || true', true);
        $dockerHost = 'unix:///run/user/'.$uid.'/docker.sock';
        pmssRunAndLog($user, 'docker ps', 'DOCKER_HOST='.escapeshellarg($dockerHost).' docker ps || true', true);
    }
}

if (!function_exists('pmssEnsureLingerAndDockerAllUsers')) {
    /** Apply linger/systemd/docker kick to all managed users with per-user logs. */
    function pmssEnsureLingerAndDockerAllUsers(): void
    {
        $list = pmssListManagedUsers();
        foreach ($list as $user) {
            if ($user === '') continue;
            pmssEnsureLingerAndDocker($user);
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

if (!function_exists('pmssApplyCgroupDefaultsAllUsers')) {
    /**
     * Apply cgroup defaults to all managed users, respecting any existing
     * settings when invoked with --respect-existing by the caller.
     */
    function pmssApplyCgroupDefaultsAllUsers(): void
    {
        $list = pmssListManagedUsers();
        $count = count($list);
        logMessage(sprintf('Applying cgroup defaults for %d user(s)', $count));
        foreach ($list as $user) {
            if ($user === '') {
                continue;
            }
            runUserStep(
                $user,
                'Applying cgroup properties (defaults, respect-existing)',
                pmssBuildCommand('php', ['/scripts/util/userConfigCgroup.php', $user, '--apply', '--defaults', '--respect-existing'])
            );
        }
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
        // Only restore crontabs for users that still exist in /etc/passwd.
        $command = sprintf(
            'bash -lc %s',
            escapeshellarg('/scripts/listUsers.php | while read -r U; do id "$U" >/dev/null 2>&1 && crontab -u "$U" /etc/seedbox/config/user.crontab.default; done')
        );
        runStep('Restoring default crontab for all users', $command);
    }
}
