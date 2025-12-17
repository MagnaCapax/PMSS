<?php
/** User maintenance helpers for update-step2. */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/systemPrep.php';
require_once __DIR__.'/users.php';
require_once __DIR__.'/../users.php';
require_once __DIR__.'/../user/log.php';

if (!function_exists('pmssRunAndLog')) {
    /** Run a shell command and log stdout/stderr + rc to the user's log file. */
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
    /** Refresh user environments and per-user runtime wiring for update-step2. */
    function pmssUpdateAllUsers(string $rutorrentIndexSha): void
    {
        $users = users::listHomeUsers();
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $count = count($users);
        logMessage(sprintf('Per-user maintenance: %d user(s) to process', $count));
        $isTty = function_exists('posix_isatty') && posix_isatty(STDOUT);

        foreach ($users as $user) {
            $userTrim = trim($user);
            if ($userTrim === '') {
                continue;
            }

            $phases = [];
            if (function_exists('pmssUpdateUserEnvironment')) {
                $phases[] = 'Environment (HTTP/ruTorrent/permissions)';
            }
            if (function_exists('pmssEnsureLingerAndDocker')) {
                $phases[] = 'Linger/systemd/rootless Docker';
            }
            // #TODO(per-user-loop): fold global sweeps (web/cron/authorized_keys) into this orchestrator.

            if ($isTty) {
                echo PHP_EOL."\033[35mUpdating user {$userTrim}\033[0m".PHP_EOL;
                foreach ($phases as $phase) {
                    echo "  \033[33m* {$phase}\033[0m".PHP_EOL;
                }
            } else {
                if (!empty($phases)) {
                    logMessage(sprintf('Updating user %s phases: %s', $userTrim, implode(', ', $phases)));
                } else {
                    logMessage(sprintf('Updating user %s', $userTrim));
                }
            }

            $userStart = microtime(true);

            // #TODO remove by end of 2027: bump legacy per-user CPUQuota=85% to host-scaled quota.
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

            pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha);
            // Keep all per-user runtime wiring in the same loop for observability and simplicity.
            if (function_exists('pmssEnsureLingerAndDocker')) {
                pmssEnsureLingerAndDocker($userTrim);
            }

            $userDuration = microtime(true) - $userStart;
            if (function_exists('pmssRecordProfile')) {
                pmssRecordProfile([
                    'description'    => 'updateUser '.$userTrim,
                    'command'        => '',
                    'status'         => 'OK',
                    'rc'             => 0,
                    'duration'       => round($userDuration, 4),
                    'dry_run'        => false,
                    'stdout_excerpt' => '',
                    'stderr_excerpt' => '',
                ]);
            }
        }
    }
}

if (!function_exists('pmssEnsureLingerAndDocker')) {
    /** Enable linger, (re)start user@UID systemd, and kick rootless Docker for a user. */
    function pmssEnsureLingerAndDocker(string $user): void
    {
        // #TODO(per-user-loop): fold this into pmssUpdateUserEnvironment so we traverse users once.
        $homeDir = "/home/{$user}";
        if (is_dir($homeDir.'/www-disabled')) {
            pmssUserLog($user, '[SKIP] User appears suspended; skipping linger/Docker wiring');
            return;
        }
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
        if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            echo "\033[36m[LINGER/DOCKER] {$user}\033[0m".PHP_EOL;
        } else {
            logMessage('[LINGER/DOCKER] '.$user);
        }

        // Enable linger to allow user@UID to run without active login.
        pmssRunAndLog($user, 'loginctl enable-linger', 'loginctl enable-linger '.escapeshellarg($user), false);

        // Guarded migration: ensure rootless Docker exists for older users.
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
    /** Run Docker's official rootless installer for users missing docker.service. */
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

        // Run Docker's official rootless installer inside a user shell.
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
    /** Verify Docker dependencies for a user (subuid/subgid + storage-driver). */
    function pmssEnsureDockerDependencies(string $user): void
    {
        // Check subuid/subgid.
        $subuid = @file_get_contents('/etc/subuid');
        $subgid = @file_get_contents('/etc/subgid');
        if ($subuid === false || strpos($subuid, $user.':') === false) {
            pmssUserLog($user, '[WARN] User missing from /etc/subuid; rootless Docker may fail.');
        }
        if ($subgid === false || strpos($subgid, $user.':') === false) {
            pmssUserLog($user, '[WARN] User missing from /etc/subgid; rootless Docker may fail.');
        }

        // Enforce fuse-overlayfs on Debian < 12.
        $distroVersion = (int)(getenv('PMSS_DISTRO_VERSION') ?: 0);
        if ($distroVersion >= 12) {
            return;
        }

        // Resolve home directory.
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

        if (isset($data['storage-driver'])) {
            return;
        }

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
        logMessage(sprintf('[START] Linger/Docker sweep for %d user(s)', count($list)));
        foreach ($list as $user) {
            if ($user === '') continue;
            pmssEnsureLingerAndDocker($user);
        }
    }
}

if (!function_exists('pmssRefreshSkeletonAndCron')) {
    /** Re-apply skeleton permissions and critical cron/FTP settings. */
    function pmssRefreshSkeletonAndCron(): void
    {
        runStep('Refreshing skeleton permissions', '/scripts/util/setupSkelPermissions.php');
        runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');
        runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');
    }
}

if (!function_exists('pmssApplyCgroupDefaultsAllUsers')) {
    /** Apply cgroup defaults for all managed users (respecting existing settings). */
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
    /** Deploy the logrotate policy for update logs when available. */
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
    /** Restore the default user crontabs from the template. */
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
