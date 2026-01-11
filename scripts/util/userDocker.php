#!/usr/bin/php
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
 *   - First verify whether the per-user Docker socket already exists.
 *   - When the socket is missing, launch dockerd-rootless.sh with the same
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
 *   - Reports systemd user-service state when a usable user bus exists, but
 *     treats it as advisory rather than the primary control surface.
 *   - Starts the daemon via dockerd-rootless.sh using a guarded non-systemd
 *     path that has been verified to work on Debian 10/11 rootless hosts.
 *   - Logs per-user actions via pmssUserLog(); noisy no-op runs are logged
 *     only when `--debug` is passed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/log.php';

$args = isset($argv) ? $argv : (isset($_SERVER['argv']) ? $_SERVER['argv'] : []);
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

if ($debug) {
    pmssUserLog($user, sprintf('userDocker: action=%s requested', $action));
}

// Helper to execute a command as the target user via su.
function userDockerRunAs(string $user, string $cmd): string
{
    $wrapper = sprintf('su %s -c %s', escapeshellarg($user), escapeshellarg($cmd));
    $out = [];
    $rc = 0;
    exec($wrapper, $out, $rc);
    return implode("\n", $out);
}

// Detect whether we can talk to the systemd user service for this user and
// whether the docker.service unit actually exists.
$showCmd = 'systemctl --user show -p FragmentPath docker.service 2>&1';
$showOut = trim(userDockerRunAs($user, $showCmd));
$hasUserBus = ($showOut !== '' && strpos($showOut, 'Failed to connect to bus') === false);
$serviceExists = $hasUserBus && (strpos($showOut, 'FragmentPath=') !== false && strpos($showOut, 'docker.service could not be found') === false);

$serviceStatus = 'unknown';
if ($hasUserBus && $serviceExists) {
    $statusCmd = 'systemctl --user is-active docker.service 2>&1';
    $statusOut = trim(userDockerRunAs($user, $statusCmd));
    $serviceStatus = $statusOut !== '' ? $statusOut : 'unknown';
}

// STATUS
if ($action === 'status') {
    if ($hasUserBus && $serviceExists) {
        echo "systemd user service status: {$serviceStatus}\n";
    } elseif ($hasUserBus && !$serviceExists) {
        echo "systemd user service status: docker.service unit missing\n";
    } else {
        echo "systemd user service status: unavailable (user bus not accessible)\n";
    }
    if (file_exists($dockerSock)) {
        echo "docker socket: present at {$dockerSock}\n";
    } else {
        echo "docker socket: not found at {$dockerSock}\n";
    }
    exit(0);
}

// STOP
if ($action === 'stop' || $action === 'restart') {
    if ($hasUserBus && $serviceExists) {
        pmssUserLog($user, 'userDocker: stopping via systemd user service');
        userDockerRunAs($user, 'systemctl --user stop docker.service');
    } else {
        pmssUserLog($user, 'userDocker: stopping rootless daemon via pkill (no systemd user unit/bus)');
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
    // Default to non-systemd rootless mode for robustness: if the socket is
    // already present, assume the daemon is running and avoid spawning a
    // duplicate instance. Only when the socket is missing do we attempt to
    // launch dockerd-rootless.sh directly. Systemd user-service mode remains
    // observable via status() but is not the primary start path yet.
    if (file_exists($dockerSock)) {
        if ($debug) {
            pmssUserLog($user, 'userDocker: docker socket already present; assuming daemon running, skipping start');
        }
        echo "Docker socket already present for {$user}; assuming running\n";
        exit(0);
    }

    // Start dockerd-rootless.sh in the same way the watchdog and manual
    // workflows do, ensuring PATH and XDG_RUNTIME_DIR are sane.
    pmssUserLog($user, 'userDocker: starting rootless daemon via dockerd-rootless.sh (no systemd user unit/bus)');
    $envCmd = sprintf(
        'XDG_RUNTIME_DIR=%s PATH=$PATH:/usr/sbin:/sbin:$HOME/bin nohup dockerd-rootless.sh >/dev/null 2>&1 &',
        "/run/user/{$uid}"
    );
    userDockerRunAs($user, $envCmd);
    echo "Docker start requested for {$user} via dockerd-rootless.sh\n";
    exit(0);
}

// Should not be reached.
exit(0);
