#!/usr/bin/env php
<?php
/**
 * userDocker.php USER ACTION
 *
 * Per-user Docker daemon helper. Provides a thin control wrapper similar to
 * systemctl for rootless Docker so operators can start/stop/restart/status
 * a tenant's Docker without retyping the environment boilerplate.
 *
 * There are two rootless Docker launch modes in the fleet:
 *   1) systemd user-service mode – docker-ce-rootless-extras plus
 *      dockerd-rootless-setuptool.sh install create a docker.service unit
 *      under the user's systemd instance (systemctl --user ...). This path
 *      exists on some hosts but is not universally deployed.
 *   2) non-systemd rootless mode – the upstream get.docker.com/rootless
 *      script (or equivalent) installs dockerd-rootless.sh and expects the
 *      daemon to be started directly from the user's shell with XDG_RUNTIME_DIR
 *      and PATH set appropriately. This is the dominant mode today.
 *
 * To favour robustness, this helper **defaults to the non-systemd rootless
 * mode** and only uses systemd user-service state for reporting or, when
 * available, a polite stop. In particular, start()/restart:
 *   - First verify whether a rootless Docker process is already running.
 *   - When no process is found, launch dockerd-rootless.sh with the same
 *     XDG_RUNTIME_DIR and PATH tweaks that the watchdog and installer use.
 *   - Deliberately do **not** attempt `systemctl --user start docker.service`
 *     until we have explicit test coverage for that path on the current
 *     distro mix.
 *
 * Usage:
 *   /scripts/util/userDocker.php USER start
 *   /scripts/util/userDocker.php USER stop
 *   /scripts/util/userDocker.php USER restart
 *   /scripts/util/userDocker.php USER status
 *
 * The script:
 *   - Reports process/socket state and whether the systemd user unit exists
 *     on disk, without querying the user bus (to avoid hangs).
 *   - Starts the daemon via dockerd-rootless.sh using a guarded non-systemd
 *     path that has been verified to work on Debian 10/11 rootless hosts.
 *   - Logs per-user actions via pmssUserLog(); noisy no-op runs are logged
 *     only when `--debug` is passed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/userLifecycle.php';

pmssRequireCli();

require_once __DIR__.'/../lib/user/log.php';
require_once __DIR__.'/../lib/user/rootlessDockerConfig.php';
require_once __DIR__.'/../lib/user/userConfigStore.php';

[$debug, $args] = pmssCliArgvDebugSplit($argv ?? null);

if (count($args) < 3) {
    fwrite(STDERR, "Usage: /scripts/util/userDocker.php USER {start|stop|restart|status} [--debug]\n");
    exit(1);
}

$user = $args[1];
$action = strtolower($args[2]);
$valid = ['start', 'stop', 'restart', 'status'];
if (!in_array($action, $valid, true)) {
    fwrite(STDERR, "Invalid action: {$action}\n");
    fwrite(STDERR, "Supported actions: start, stop, restart, status\n");
    exit(1);
}

/** Return a single-line value safe for stderr and log context. */
function userDockerSafeLabel(string $value): string
{
    $label = preg_replace('/[\r\n\0]+/', '?', $value);
    return is_string($label) && $label !== '' ? $label : '(empty)';
}

/** Resolve only managed account names with a positive UID before shelling out. */
function userDockerAccountInfo(string $user, ?string &$reason = null): ?array
{
    $reason = null;
    if (!pmssValidateUsername($user)) {
        $reason = 'invalid';
        return null;
    }

    $info = pmssUserAccountLookup($user);
    if ($info === null) {
        $reason = 'unknown';
        return null;
    }

    if (pmssPasswdEntryPositiveUid($info) === null) {
        $reason = 'unsafe_uid';
        return null;
    }

    return $info;
}

// Resolve UID so we can derive runtime paths.
$accountReason = null;
$info = userDockerAccountInfo($user, $accountReason);
if ($info === null) {
    $safeUser = userDockerSafeLabel($user);
    if ($accountReason === 'invalid') {
        fwrite(STDERR, "Invalid user: {$safeUser}\n");
    } elseif ($accountReason === 'unsafe_uid') {
        fwrite(STDERR, "Unsafe user UID: {$safeUser}\n");
    } else {
        fwrite(STDERR, "Unknown user: {$safeUser}\n");
    }
    exit(1);
}
$uid = (int) $info['uid'];
$runtimeDir = "/run/user/{$uid}";
$dockerSock = $runtimeDir.'/docker.sock';
$home = isset($info['dir']) ? (string) $info['dir'] : '';
$dockerUnitPath = $home !== '' ? $home.'/.config/systemd/user/docker.service' : '';
$serviceExists = ($dockerUnitPath !== '' && is_file($dockerUnitPath));
$userDockerStartTimeoutSec = 300;
$dockerStopCmd = 'pkill -f dockerd-rootless.sh || pkill -f "dockerd-rootless" || pkill dockerd || true';

if ($debug) {
    pmssUserLog($user, sprintf('userDocker: action=%s requested', $action));
}

// Helper to execute a command as the target user via su, with an optional timeout.
function userDockerRunAs(string $user, string $cmd, ?int $timeoutSeconds = null, ?int &$rc = null): string
{
    static $timeoutBinResolved = false;
    static $timeoutBin = null;

    $target = userDockerAccountInfo($user);
    if ($target === null) {
        $rc = 127;
        return '';
    }

    $wrapper = pmssBuildUserShellCommand($user, $cmd);
    $currentUid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
    if ($target !== null && $currentUid > 0 && $currentUid === (int) $target['uid']) {
        // When already running as the target user (e.g. per-user web UI),
        // avoid `su` so interactive password prompts do not block automation.
        $wrapper = sprintf('/bin/bash -lc %s', escapeshellarg($cmd));
    }

    if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
        if (!$timeoutBinResolved) {
            $timeoutBinResolved = true;
            $candidates = ['/usr/bin/timeout', '/bin/timeout'];
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $timeoutBin = $candidate;
                    break;
                }
            }
        }
        if ($timeoutBin !== null) {
            $wrapper = sprintf(
                '%s --kill-after=%s %s %s',
                escapeshellarg($timeoutBin),
                escapeshellarg('5s'),
                escapeshellarg($timeoutSeconds.'s'),
                $wrapper
            );
        }
    }
    $out = [];
    $rcLocal = 0;
    exec($wrapper, $out, $rcLocal);
    $rc = $rcLocal;
    return implode("\n", $out);
}

/**
 * Detect the current host cgroup mode for rootless Docker setup.
 */
function userDockerCgroupMode(): string
{
    return pmssCgroupModeWithDefault('v1');
}

/**
 * Ensure cgroup v2 hosts have a daemon.json with the rootless cgroupfs driver.
 */
function userDockerEnsureCgroupfsDaemonConfig(string $user, string $home, int $uid, int $gid): void
{
    if (userDockerCgroupMode() !== 'v2') {
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

/**
 * Collect running rootless Docker-related PIDs for a user.
 */
function userDockerCollectPids(string $user, bool $debug = false, ?bool &$checkOk = null): array
{
    $pids = [];
    $checks = [
        ['label' => 'dockerd-rootless.sh', 'pattern' => 'dockerd-rootless\\.sh', 'options' => ['full' => true, 'captureStderr' => true]],
        ['label' => 'dockerd-rootless', 'pattern' => 'dockerd-rootless', 'options' => ['full' => true, 'captureStderr' => true]],
        ['label' => 'dockerd', 'pattern' => 'dockerd', 'options' => ['exact' => true, 'captureStderr' => true]],
    ];
    $checkOkLocal = true;

    foreach ($checks as $check) {
        $out = []; $rc = 0;
        foreach (pmssUserWatchdogProcessPids($user, (string) $check['pattern'], (array) $check['options'], $rc, $out) as $pid) {
            $pids[$pid] = true;
        }
        if ($rc === 127) {
            $checkOkLocal = false;
            break;
        }
        if ($debug) {
            $joined = implode(' | ', $out);
            if (strlen($joined) > 300) {
                $joined = substr($joined, 0, 300).'...';
            }
            pmssUserLog($user, sprintf('userDocker: %s pgrep rc=%d out=%s', $check['label'], $rc, $joined !== '' ? $joined : '(empty)'));
        }
        if ($rc === 0) {
            continue;
        }
        if ($rc !== 1) {
            pmssUserLog($user, sprintf('[WARN] Failed to check %s process state (rc=%d): %s', $check['label'], $rc, implode(' | ', $out)));
        }
    }

    if (!$checkOkLocal) {
        $out = [];
        $rc = 0;
        $cmd = sprintf('ps -u %s -o pid=,cmd= 2>&1', escapeshellarg($user));
        exec($cmd, $out, $rc);
        if ($rc === 0) {
            $checkOkLocal = true;
            foreach ($out as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $line, 2);
                if (!is_array($parts) || count($parts) < 2) {
                    continue;
                }
                $pid = $parts[0];
                $cmdline = $parts[1];
                if (!ctype_digit($pid)) {
                    continue;
                }
                if (stripos($cmdline, 'dockerd') !== false) {
                    $pids[(int) $pid] = true;
                }
            }
            if ($debug) {
                $joined = implode(' | ', $out);
                if (strlen($joined) > 300) {
                    $joined = substr($joined, 0, 300).'...';
                }
                pmssUserLog($user, sprintf('userDocker: ps fallback rc=%d out=%s', $rc, $joined !== '' ? $joined : '(empty)'));
            }
        } else {
            $checkOkLocal = false;
            pmssUserLog($user, sprintf('[WARN] Failed to check Docker process state via ps (rc=%d): %s', $rc, implode(' | ', $out)));
        }
    }

    $checkOk = $checkOkLocal;
    return array_keys($pids);
}

// Detect running rootless Docker processes and unit presence.
$processCheckOk = true;
$dockerPids = userDockerCollectPids($user, $debug, $processCheckOk);

// STATUS
if ($action === 'status') {
    if (!empty($dockerPids)) {
        sort($dockerPids);
    }
    $socketPresent = file_exists($dockerSock);
    echo !empty($dockerPids)
        ? "docker process: running (pid(s): ".implode(', ', $dockerPids).")\n"
        : ($processCheckOk ? "docker process: not running\n" : "docker process: unknown (process check failed)\n");
    echo $serviceExists ? "systemd user service unit: present at {$dockerUnitPath}\n" : "systemd user service unit: missing\n";
    echo $socketPresent ? "docker socket: present at {$dockerSock}\n" : "docker socket: not found at {$dockerSock}\n";
    if ($debug) {
        echo "debug: home={$home}\n";
        echo "debug: runtime_dir={$runtimeDir}\n";
        echo "debug: unit_path={$dockerUnitPath}\n";
        echo "debug: process_check_ok=".($processCheckOk ? 'yes' : 'no')."\n";
        pmssUserLog($user, sprintf(
            'userDocker: debug status home=%s runtime_dir=%s unit_path=%s socket=%s process_check_ok=%s',
            $home,
            $runtimeDir,
            $dockerUnitPath,
            $socketPresent ? 'present' : 'missing',
            $processCheckOk ? 'yes' : 'no'
        ));
    }
    exit(0);
}

// STOP
if ($action === 'stop' || $action === 'restart') {
    pmssUserLog($user, $serviceExists
        ? 'userDocker: stopping via systemd user service'
        : 'userDocker: stopping rootless daemon via pkill (no systemd user unit)');
    if ($serviceExists) {
        $stopRc = 0;
        userDockerRunAs($user, 'systemctl --user stop docker.service', $userDockerStartTimeoutSec, $stopRc);
        if ($stopRc === 124) {
            pmssUserLog($user, 'userDocker: systemctl stop timed out; falling back to pkill');
            userDockerRunAs($user, $dockerStopCmd);
        }
    } else {
        // Best-effort stop for non-systemd rootless: kill dockerd-rootless.sh/dockerd for this user.
        userDockerRunAs($user, $dockerStopCmd);
    }
    if ($action === 'stop') {
        echo "Docker stop requested for {$user}\n";
        exit(0);
    }
    // fall through to start for restart
}

// START
if ($action === 'start' || $action === 'restart') {
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
        exit(0);
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
        exit(0);
    }
    $socketPresent = file_exists($dockerSock);
    if (!$startCheckOk && $socketPresent) {
        pmssUserLog($user, 'userDocker: process check failed with socket present; skipping start');
        echo "Docker socket present for {$user}, but process check failed; skipping start\n";
        exit(0);
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
    userDockerRunAs($user, $envCmd, $userDockerStartTimeoutSec);
    echo "Docker start requested for {$user} via dockerd-rootless.sh\n";
}

// Should not be reached.
