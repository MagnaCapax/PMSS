<?php
/**
 * User maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/systemPrep.php';
require_once __DIR__.'/users.php';
require_once __DIR__.'/../users.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/../user/log.php';
require_once __DIR__.'/../user/directories.php';

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
     * Keep this a simple foreach(users) orchestrator; avoid accumulating
     * extra cross-cutting concerns beyond logging and the legacy CPUQuota
     * fix block.
     */
    function pmssUpdateAllUsers(string $rutorrentIndexSha): void
    {
        $users = users::listHomeUsers();
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        logMessage(sprintf('Per-user maintenance: %d user(s) to process', count($users)));
        $isTty = function_exists('posix_isatty') && posix_isatty(STDOUT);
        $htpasswdHelper = '/scripts/util/checkUserHtpasswd.php';
        $hasHtpasswdHelper = is_file($htpasswdHelper);
        $lighttpdChecker = '/scripts/cron/checkLighttpdInstances.php';
        $hasLighttpdChecker = is_file($lighttpdChecker);

        $runUserMaintenance = static function (string $user, string $label, string $command): void {
            $rc = runUserStep($user, $label, $command);
            if ($rc !== 0 && function_exists('pmssUserLog')) {
                pmssUserLog($user, sprintf('[WARN] %s failed (rc=%d)', $label, $rc));
            }
        };

        foreach ($users as $user) {
            $userTrim = trim($user);
            if ($userTrim === '') {
                continue;
            }
            $normalized = pmssNormalizeUsername($userTrim);
            if ($normalized !== $userTrim || !pmssValidateUsername($userTrim)) {
                logMessage(sprintf('[WARN] Skipping invalid username during update-step2: %s', $userTrim));
                continue;
            }

            $phases = [];
            if (function_exists('pmssUpdateUserEnvironment')) {
                $label = 'Environment (HTTP/ruTorrent/permissions';
                if (function_exists('pmssEnsureLingerAndDocker')) {
                    $label .= ' + linger/systemd/rootless Docker';
                }
                $label .= ')';
                $phases[] = $label;
            }
            if ($hasHtpasswdHelper) {
                $phases[] = 'Legacy htpasswd sync';
            }
            if ($hasLighttpdChecker) {
                $phases[] = 'Lighttpd instance check';
            }

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

            pmssUpdateUserEnvironment($userTrim, $rutorrentIndexSha);

            if ($hasHtpasswdHelper) {
                $command = pmssBuildCommand($htpasswdHelper, [$userTrim]);
                $runUserMaintenance($userTrim, 'Synchronizing per-user htpasswd', $command);
            }
            if ($hasLighttpdChecker) {
                $command = pmssBuildCommand($lighttpdChecker, [$userTrim]);
                $runUserMaintenance($userTrim, 'Checking lighttpd instance', $command);
            }

            $userDuration = microtime(true) - $userStart;
            if (function_exists('pmssUserLog')) {
                pmssUserLog($userTrim, sprintf('update-step2: user maintenance finished (%.2fs)', $userDuration));
            }
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
    /**
     * Enable linger, (re)start user@UID systemd instance, and kick rootless Docker for a user.
     * Logs detailed command output to `/var/log/pmss/users/<username>.log` via `pmssUserLog()`.
     *
     * #TODO(docker-linger-split): Refactor into separate helpers so linger/systemd
     * setup and Docker start/status are handled by distinct functions. This will
     * make it easier to evolve Docker launch behaviour independently of user
     * session wiring.
     */
    function pmssEnsureLingerAndDocker(string $user): void
    {
        if (is_dir("/home/{$user}/www-disabled")) {
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

        // Ensure rootless Docker is installed for users that predate the
        // rollout. This is a guarded migration: if the unit already exists
        // or the helper binary is missing, we log and skip.
        pmssEnsureRootlessDockerInstalled($user);

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

        // 2. Enforce fuse-overlayfs for rootless Docker when available.
        $distroVersion = (int)(getenv('PMSS_DISTRO_VERSION') ?: 0);
        if ($distroVersion <= 0) {
            pmssUserLog($user, '[WARN] PMSS_DISTRO_VERSION missing; skipping Docker storage-driver enforcement');
            return;
        }

        // Resolve home directory
        $uinfo = posix_getpwnam($user);
        if (!$uinfo) return;
        $home = $uinfo['dir'];
        $uid  = $uinfo['uid'];
        $gid  = $uinfo['gid'];

        // Resolve fuse-overlayfs availability once per run.
        static $fuseOverlayfsAvailable = null;
        static $fuseOverlayfsPath = null;
        if ($fuseOverlayfsAvailable === null) {
            $candidates = ['/usr/bin/fuse-overlayfs', '/usr/local/bin/fuse-overlayfs'];
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $fuseOverlayfsPath = $candidate;
                    break;
                }
            }

            if ($fuseOverlayfsPath === null) {
                $fuseOverlayfsAvailable = false;
            } else {
                $out = [];
                $rc = 1;
                @exec(escapeshellarg($fuseOverlayfsPath).' --version >/dev/null 2>&1', $out, $rc);
                $fuseOverlayfsAvailable = ($rc === 0);
            }
        }
        $fuseOverlayfsStatus = $fuseOverlayfsAvailable ? 'available' : ($fuseOverlayfsPath === null ? 'missing' : 'unusable');

        $configDir  = $home.'/.config/docker';
        $configFile = $configDir.'/daemon.json';

        $userLog = static function (string $message) use ($user): void { pmssUserLog($user, $message); };
        if (!pmssEnsureUserHomeDir($user, $home, '.config/docker', 0700, $userLog, 0700)) {
            pmssUserLog($user, '[WARN] Failed to ensure ~/.config/docker');
            return;
        }

        $current = @file_get_contents($configFile);
        $data = $current ? json_decode($current, true) : [];
        if (!is_array($data)) $data = [];
        $expectedConfig = json_encode(
            ['storage-driver' => 'fuse-overlayfs'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        // If storage-driver is already set, respect it unless it is our default.
        if (isset($data['storage-driver'])) {
            if ($data['storage-driver'] !== 'fuse-overlayfs') {
                return;
            }

            if ($fuseOverlayfsAvailable) {
                return;
            }

            $isDefaultOnly = (count($data) === 1);
            $matchesDefault = ($current !== false && trim($current) === trim($expectedConfig));
            if ($isDefaultOnly && $matchesDefault && is_file($configFile)) {
                $realHome = realpath($home);
                $realConfig = realpath($configFile);
                if ($realHome === false || $realConfig === false || strpos($realConfig, $realHome.'/') !== 0) {
                    pmssUserLog($user, '[WARN] Refusing to remove daemon.json outside expected home path');
                    return;
                }

                if (@unlink($configFile)) {
                    pmssUserLog($user, '[WARN] Removed daemon.json storage-driver because fuse-overlayfs is unavailable');
                } else {
                    pmssUserLog($user, '[WARN] Failed to remove daemon.json after fuse-overlayfs check');
                }
            } else {
                pmssUserLog($user, sprintf('[WARN] fuse-overlayfs %s; leaving existing daemon.json untouched', $fuseOverlayfsStatus));
            }
            return;
        }

        if (!$fuseOverlayfsAvailable) {
            pmssUserLog($user, sprintf('[WARN] fuse-overlayfs %s; skipping storage-driver enforcement', $fuseOverlayfsStatus));
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
