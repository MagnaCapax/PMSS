<?php
/**
 * User maintenance helpers for update-step2.
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/systemPrep.php';
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

if (!function_exists('pmssUpdateAllUsers')) {
    /**
     * Refresh ruTorrent and skeleton data for every provisioned user.
     *
     * #TODO(user-maint): keep this as a simple foreach(users) orchestrator;
     * avoid accumulating extra cross-cutting concerns here beyond logging and
     * the temporary CPUQuota fix block.
     */
    function pmssUpdateAllUsers(string $rutorrentIndexSha): void
    {
        $users = users::listHomeUsers();
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($users);
        logMessage(sprintf('Per-user maintenance: %d user(s) to process', $count));

        foreach ($users as $user) {
            if (trim($user) === '') {
                continue;
            }

            // #TODO Remove this fix block by end of 2027.
            // Legacy fix: Detect "CPUQuota=85%" overrides on per-user slices and
            // bump them to a host-based quota derived from total CPU threads so
            // users are no longer capped to 85% of a single core.
            $uinfo = posix_getpwnam($user);
            if ($uinfo && isset($uinfo['uid'])) {
                $uid = (int)$uinfo['uid'];
                $sliceDir = '/etc/systemd/system/user-'.$uid.'.slice.d';
                $needsFix = false;
                if (is_dir($sliceDir)) {
                    $files = glob($sliceDir.'/*.conf') ?: [];
                    foreach ($files as $f) {
                        $content = @file_get_contents($f);
                        if ($content && preg_match('/^CPUQuota\s*=\s*85%$/m', $content)) {
                            $needsFix = true;
                            break;
                        }
                    }
                }
                if ($needsFix) {
                    $threads = pmssTotalCpuThreads();
                    $newQuota = ($threads > 0) ? ($threads * 85) : 600;
                    $slice = 'user-'.$uid.'.slice';
                    runStep(
                        "Fixing legacy 85% CPUQuota for {$user}",
                        'systemctl set-property '.escapeshellarg($slice).' CPUQuota='.$newQuota.'%'
                    );
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

        // Ensure rootless Docker is installed for users that predate the
        // rollout. This is a guarded migration: if the unit already exists
        // or the helper binary is missing, we log and skip.
        if (function_exists('pmssEnsureRootlessDockerInstalled')) {
            pmssEnsureRootlessDockerInstalled($user);
        }

        // Check and (re)start the per-user systemd instance.
        pmssRunAndLog($user, 'systemctl status user@.service (pre)', 'systemctl --no-pager -l status user@'.$uid.'.service || true', false);
        pmssRunAndLog($user, 'systemctl start user@.service', 'systemctl start user@'.$uid.'.service', false);
        pmssRunAndLog($user, 'systemctl status user@.service (post)', 'systemctl --no-pager -l status user@'.$uid.'.service || true', false);

        // Start rootless Docker for the user via the shared helper so both
        // systemd and non-systemd rootless modes are handled consistently.
        pmssEnsureDockerDependencies($user);
        $startCmd = sprintf('php /scripts/util/userDocker.php %s start', escapeshellarg($user));
        pmssRunAndLog($user, 'userDocker start', $startCmd, false);
        $statusCmd = sprintf('php /scripts/util/userDocker.php %s status', escapeshellarg($user));
        pmssRunAndLog($user, 'userDocker status', $statusCmd, false);
    }
}

if (!function_exists('pmssEnsureRootlessDockerInstalled')) {
    /**
     * Run dockerd-rootless-setuptool.sh for users that do not yet have a
     * per-user docker.service unit. This is intended as a migration helper
     * for existing tenants to match the behaviour of new-account provisioning.
     */
    function pmssEnsureRootlessDockerInstalled(string $user): void
    {
        $uinfo = posix_getpwnam($user);
        if (!$uinfo || !isset($uinfo['dir'])) {
            pmssUserLog($user, '[WARN] Unable to resolve passwd entry; skipping rootless Docker install');
            return;
        }

        $home    = $uinfo['dir'];
        $unitDir = $home.'/.config/systemd/user';
        $unit    = $unitDir.'/docker.service';

        // If the user already has a docker.service unit, assume the rootless
        // install has been performed (either by PMSS or manually).
        if (is_file($unit)) {
            pmssUserLog($user, '[SKIP] Rootless Docker systemd unit already present');
            return;
        }

        // Use Docker\'s official rootless install script in the same way an
        // operator would invoke it manually:
        //   curl -fsSL https://get.docker.com/rootless | sh
        // Run this inside a user shell so any environment tweaks it performs
        // are applied to the correct home directory. Ensure sysadmin tools
        // (sysctl, etc.) are on PATH so the helper does not fail mid-run.
        $installCmd = 'export PATH=$PATH:/usr/sbin:/sbin; curl -fsSL https://get.docker.com/rootless | sh';
        $wrapped = sprintf(
            'machinectl shell %1$s@ /bin/bash -lc %2$s',
            escapeshellarg($user),
            escapeshellarg($installCmd)
        );
        pmssRunAndLog($user, 'docker.com rootless install script', $wrapped, false);

        // After running the installer, verify that the user unit was created.
        clearstatcache();
        if (is_file($unit)) {
            pmssUserLog($user, '[OK] Rootless Docker unit docker.service created for user');
        } else {
            pmssUserLog($user, '[WARN] Rootless Docker install script completed but docker.service is still missing');
        }
    }
}

if (!function_exists('pmssEnsureDockerDependencies')) {
    /**
     * Verify Docker dependencies for a user: subuid/subgid and daemon.json storage-driver.
     */
    function pmssEnsureDockerDependencies(string $user): void
    {
        // 1. Check subuid/subgid
        $subuid = @file_get_contents('/etc/subuid');
        $subgid = @file_get_contents('/etc/subgid');
        if ($subuid === false || strpos($subuid, $user.':') === false) {
            pmssUserLog($user, '[WARN] User missing from /etc/subuid; rootless Docker may fail.');
        }
        if ($subgid === false || strpos($subgid, $user.':') === false) {
            pmssUserLog($user, '[WARN] User missing from /etc/subgid; rootless Docker may fail.');
        }

        // 2. Enforce fuse-overlayfs on Debian < 12
        $distroVersion = (int)(getenv('PMSS_DISTRO_VERSION') ?: 0);
        if ($distroVersion >= 12) {
            return;
        }

        // Resolve home directory
        $uinfo = posix_getpwnam($user);
        if (!$uinfo) return;
        $home = $uinfo['dir'];
        $uid  = $uinfo['uid'];
        $gid  = $uinfo['gid'];

        $configDir  = $home.'/.config/docker';
        $configFile = $configDir.'/daemon.json';

        if (!is_dir($configDir)) {
            if (!mkdir($configDir, 0700, true)) {
                pmssUserLog($user, '[WARN] Failed to create ~/.config/docker');
                return;
            }
            chown($configDir, $uid);
            chgrp($configDir, $gid);
        }

        $current = @file_get_contents($configFile);
        $data = $current ? json_decode($current, true) : [];
        if (!is_array($data)) $data = [];

        // If storage-driver is already set, respect it.
        if (isset($data['storage-driver'])) {
            return;
        }

        // Otherwise, enforce fuse-overlayfs
        $data['storage-driver'] = 'fuse-overlayfs';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($configFile, $json) !== false) {
            chown($configFile, $uid);
            chgrp($configFile, $gid);
            chmod($configFile, 0600);
            pmssUserLog($user, '[INFO] Configured Docker storage-driver: fuse-overlayfs');
        } else {
            pmssUserLog($user, '[WARN] Failed to write daemon.json');
        }
    }
}

if (!function_exists('pmssEnsureLingerAndDockerAllUsers')) {
    /** Apply linger/systemd/docker kick to all managed users with per-user logs. */
    function pmssEnsureLingerAndDockerAllUsers(): void
    {
        $list = users::listHomeUsers();
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
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
        $list = users::listHomeUsers();
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
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
