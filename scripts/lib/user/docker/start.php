<?php
/** Rootless daemon startup, linger repair and existing cgroup config fallback. Loaded by cli.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Ensure cgroup v2 hosts have a daemon.json with the rootless cgroupfs driver.
 */
function userDockerEnsureCgroupfsDaemonConfig(string $user, string $home, int $uid, int $gid): void
{
    if (pmssCgroupModeWithDefault('v1') !== 'v2') {
        return;
    }

    $result = pmssUserRootlessDockerConfigConverge($user, $home, $uid, $gid, [
        'create_when_missing' => true,
    ]);
    if (!$result['ok']) {
        $messages = [
            'ensure_dir_failed' => '[WARN] userDocker: failed to ensure ~/.config/docker for cgroup v2 rootless Docker',
            'unsafe_config_file' => '[WARN] userDocker: refusing unsafe ~/.config/docker/daemon.json target',
            'invalid_json' => '[WARN] userDocker: existing daemon.json is invalid JSON; leaving it unchanged',
            'lossy_json' => '[WARN] userDocker: daemon.json has an empty or numeric-key object PMSS cannot rewrite safely; leaving it unchanged',
            'encode_failed' => '[WARN] userDocker: failed to encode daemon.json for cgroup v2 rootless Docker',
            'temp_failed' => '[WARN] userDocker: failed to create temporary daemon.json for cgroup v2 rootless Docker',
            'write_failed' => '[WARN] userDocker: failed to write ~/.config/docker/daemon.json for cgroup v2 rootless Docker',
            'chown_failed' => '[WARN] userDocker: failed to set daemon.json owner for cgroup v2 rootless Docker',
            'chgrp_failed' => '[WARN] userDocker: failed to set daemon.json group for cgroup v2 rootless Docker',
            'chmod_failed' => '[WARN] userDocker: failed to set daemon.json mode for cgroup v2 rootless Docker',
            'rename_failed' => '[WARN] userDocker: failed to install daemon.json for cgroup v2 rootless Docker',
        ];
        pmssUserLog($user, $messages[$result['reason']] ?? '[WARN] userDocker: failed to converge daemon.json');
        return;
    }
    if ($result['changed']) {
        pmssUserLog($user, $result['created']
            ? 'userDocker: wrote ~/.config/docker/daemon.json for cgroup v2 rootless Docker'
            : 'userDocker: updated ~/.config/docker/daemon.json for cgroup v2 rootless Docker');
    }
}

/** Start through the existing non-systemd path, retaining policy and liveness gates. */
function userDockerStart(string $user, array $info, string $runtimeDir, string $dockerSock, bool $debug): int
{
    $uid = (int) $info['uid'];
    $home = isset($info['dir']) ? (string) $info['dir'] : '';
    $userDockerStartTimeoutSec = 300;
    $userConfigStore = new UserConfigStore();
    if (!pmssUserDockerEnabled($user, $userConfigStore)) {
        $dockerRamFloorMiB = pmssUserDockerMinRamMiB();
        pmssUserLog($user, sprintf(
            'userDocker: start blocked; Docker disabled by config or RAM floor (%d MiB)',
            $dockerRamFloorMiB
        ));
        echo sprintf(
            "Docker start blocked for %s: Docker is disabled or RAM is below %d MiB\n",
            $user,
            $dockerRamFloorMiB
        );
        return 0;
    }

    // Rootless dockerd binds its socket in /run/user/UID, which logind only creates for a
    // logged-in or LINGERING user. Accounts whose linger wiring did not complete at
    // provisioning land here with no runtime dir, and the 5-min checkRootlessDocker watchdog
    // would otherwise retry forever (it only calls this start path, never enabling linger).
    // Enable linger (root) so logind creates the persistent runtime dir before we start.
    if (!is_dir($runtimeDir)) {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            pmssUserLog($user, sprintf('userDocker: %s missing and not running as root; cannot enable linger before start', $runtimeDir));
        } else {
            pmssUserLog($user, sprintf('userDocker: %s missing; enabling linger so logind creates the runtime dir', $runtimeDir));
            $userDockerLingerLog = static function (string $message) use ($user): void {
                pmssUserLog($user, $message);
            };
            foreach (pmssUserDockerLingerEnsureCommands($user, $uid) as $lingerCmd) {
                runCommand($lingerCmd, false, $userDockerLingerLog);
            }
            for ($waitTick = 0; $waitTick < 15 && !is_dir($runtimeDir); $waitTick++) {
                usleep(200000);
            }
            pmssUserLog($user, is_dir($runtimeDir)
                ? sprintf('userDocker: linger enabled; %s now present', $runtimeDir)
                : sprintf('userDocker: %s still absent after enabling linger; start may fail', $runtimeDir));
        }
    }

    // Default to non-systemd rootless mode for robustness; check running
    // processes before attempting to start a new daemon.
    $startCheckOk = true;
    $dockerPids = userDockerCollectPids($user, $debug, $startCheckOk);
    if (!empty($dockerPids)) {
        sort($dockerPids);
        if ($debug) {
            pmssUserLog($user, 'userDocker: dockerd already running; skipping start (pids: '.implode(', ', $dockerPids).')');
        }
        echo "Docker already running for {$user} (pid(s): ".implode(', ', $dockerPids)."); skipping start\n";
        return 0;
    }
    $socketPresent = file_exists($dockerSock);
    if (!$startCheckOk && $socketPresent) {
        pmssUserLog($user, 'userDocker: process check failed with socket present; skipping start');
        echo "Docker socket present for {$user}, but process check failed; skipping start\n";
        return 0;
    }
    if ($socketPresent && $debug) {
        pmssUserLog($user, 'userDocker: docker socket present without running dockerd; attempting start');
    }

    userDockerEnsureCgroupfsDaemonConfig($user, $home, $uid, (int) $info['gid']);

    // Start dockerd-rootless.sh in the same way the watchdog and manual
    // workflows do, ensuring PATH and XDG_RUNTIME_DIR are sane.
    pmssUserLog($user, 'userDocker: starting rootless daemon via dockerd-rootless.sh (no systemd user unit)');
    $envCmd = sprintf(
        'XDG_RUNTIME_DIR=%s PATH=$PATH:/usr/sbin:/sbin:$HOME/bin nohup dockerd-rootless.sh >/dev/null 2>&1 &',
        $runtimeDir
    );
    $launchRc = 0;
    userDockerRunAs($user, $envCmd, $userDockerStartTimeoutSec, $launchRc, true);
    echo "Docker start requested for {$user} via dockerd-rootless.sh\n";
    return 0;
}
