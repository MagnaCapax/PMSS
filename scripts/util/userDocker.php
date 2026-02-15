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

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/log.php';

$args = $argv ?? ($_SERVER['argv'] ?? []);
$debug = in_array('--debug', $args, true);
$args = array_values(array_filter($args, static function ($arg) {
    return $arg !== '--debug';
}));
$effectiveArgc = count($args);

if ($effectiveArgc < 3) {
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

// Resolve UID so we can derive runtime paths.
$info = function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
if ($info === false || !isset($info['uid'])) {
    fwrite(STDERR, "Unknown user: {$user}\n");
    exit(1);
}
$uid = (int) $info['uid'];
$dockerSock = "/run/user/{$uid}/docker.sock";
$home = isset($info['dir']) ? (string) $info['dir'] : '';
$dockerUnitPath = $home !== '' ? $home.'/.config/systemd/user/docker.service' : '';
$serviceExists = ($dockerUnitPath !== '' && is_file($dockerUnitPath));
$userDockerStartTimeoutSec = 300;

if ($debug) {
    pmssUserLog($user, sprintf('userDocker: action=%s requested', $action));
}

// Helper to execute a command as the target user via su, with an optional timeout.
function userDockerRunAs(string $user, string $cmd, ?int $timeoutSeconds = null, ?int &$rc = null): string
{
    static $timeoutBinResolved = false;
    static $timeoutBin = null;

    $wrapper = sprintf('su %s -c %s', escapeshellarg($user), escapeshellarg($cmd));
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
                '%s %s %s',
                escapeshellarg($timeoutBin),
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
 * Collect running rootless Docker-related PIDs for a user.
 *
 * #TODO(user-docker-process-check): consolidate per-user start process checks
 * with other launchers (startRtorrent/startLighttpd) into a shared helper.
 */
function userDockerCollectPids(string $user, bool $debug = false, ?bool &$checkOk = null): array
{
    $pids = [];
    $checks = [
        ['label' => 'dockerd-rootless.sh', 'cmd' => 'pgrep -u %s -f dockerd-rootless\\.sh 2>&1'],
        ['label' => 'dockerd-rootless', 'cmd' => 'pgrep -u %s -f dockerd-rootless 2>&1'],
        ['label' => 'dockerd', 'cmd' => 'pgrep -u %s -x dockerd 2>&1'],
    ];
    $pgrepMissing = false;
    $checkOkLocal = true;

    foreach ($checks as $check) {
        $out = [];
        $rc = 0;
        $cmd = sprintf($check['cmd'], escapeshellarg($user));
        exec($cmd, $out, $rc);
        if ($rc === 127) {
            $pgrepMissing = true;
            $checkOkLocal = false;
            break;
        }
        if ($rc === 0) {
            foreach ($out as $line) {
                $pid = trim((string) $line);
                if ($pid !== '' && ctype_digit($pid)) {
                    $pids[(int) $pid] = true;
                }
            }
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

    if ($pgrepMissing) {
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
        echo "docker process: running (pid(s): ".implode(', ', $dockerPids).")\n";
    } elseif (!$processCheckOk) {
        echo "docker process: unknown (process check failed)\n";
    } else {
        echo "docker process: not running\n";
    }
    if ($serviceExists) {
        echo "systemd user service unit: present at {$dockerUnitPath}\n";
    } else {
        echo "systemd user service unit: missing\n";
    }
    if (file_exists($dockerSock)) {
        echo "docker socket: present at {$dockerSock}\n";
    } else {
        echo "docker socket: not found at {$dockerSock}\n";
    }
    if ($debug) {
        $runtimeDir = "/run/user/{$uid}";
        echo "debug: home={$home}\n";
        echo "debug: runtime_dir={$runtimeDir}\n";
        echo "debug: unit_path={$dockerUnitPath}\n";
        echo "debug: process_check_ok=".($processCheckOk ? 'yes' : 'no')."\n";
        pmssUserLog($user, sprintf(
            'userDocker: debug status home=%s runtime_dir=%s unit_path=%s socket=%s process_check_ok=%s',
            $home,
            $runtimeDir,
            $dockerUnitPath,
            file_exists($dockerSock) ? 'present' : 'missing',
            $processCheckOk ? 'yes' : 'no'
        ));
    }
    exit(0);
}

// STOP
if ($action === 'stop' || $action === 'restart') {
    if ($serviceExists) {
        pmssUserLog($user, 'userDocker: stopping via systemd user service');
        $stopRc = 0;
        userDockerRunAs($user, 'systemctl --user stop docker.service', $userDockerStartTimeoutSec, $stopRc);
        if ($stopRc === 124) {
            pmssUserLog($user, 'userDocker: systemctl stop timed out; falling back to pkill');
            userDockerRunAs($user, 'pkill -f dockerd-rootless.sh || pkill -f "dockerd-rootless" || pkill dockerd || true');
        }
    } else {
        pmssUserLog($user, 'userDocker: stopping rootless daemon via pkill (no systemd user unit)');
        // Best-effort stop for non-systemd rootless: kill dockerd-rootless.sh/dockerd for this user.
        userDockerRunAs($user, 'pkill -f dockerd-rootless.sh || pkill -f "dockerd-rootless" || pkill dockerd || true');
    }
    if ($action === 'stop') {
        echo "Docker stop requested for {$user}\n";
        exit(0);
    }
    // fall through to start for restart
}

// START
if ($action === 'start' || $action === 'restart') {
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
    if (!$startCheckOk && file_exists($dockerSock)) {
        pmssUserLog($user, 'userDocker: process check failed with socket present; skipping start');
        echo "Docker socket present for {$user}, but process check failed; skipping start\n";
        exit(0);
    }
    if (file_exists($dockerSock) && $debug) {
        pmssUserLog($user, 'userDocker: docker socket present without running dockerd; attempting start');
    }

    // Start dockerd-rootless.sh in the same way the watchdog and manual
    // workflows do, ensuring PATH and XDG_RUNTIME_DIR are sane.
    pmssUserLog($user, 'userDocker: starting rootless daemon via dockerd-rootless.sh (no systemd user unit)');
    $envCmd = sprintf(
        'XDG_RUNTIME_DIR=%s PATH=$PATH:/usr/sbin:/sbin:$HOME/bin nohup dockerd-rootless.sh >/dev/null 2>&1 &',
        "/run/user/{$uid}"
    );
    userDockerRunAs($user, $envCmd, $userDockerStartTimeoutSec);
    echo "Docker start requested for {$user} via dockerd-rootless.sh\n";
    exit(0);
}

// Should not be reached.
exit(0);
