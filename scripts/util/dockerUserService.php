#!/usr/bin/php
<?php
/**
 * dockerUserService.php USER ACTION
 *
 * Per-user Docker daemon helper. Provides a thin control wrapper similar to
 * systemctl for rootless Docker so operators can start/stop/restart/status
 * a tenant's Docker without retyping the environment boilerplate.
 *
 * Usage:
 *   /scripts/util/dockerUserService.php USER start
 *   /scripts/util/dockerUserService.php USER stop
 *   /scripts/util/dockerUserService.php USER restart
 *   /scripts/util/dockerUserService.php USER status
 *
 * The script:
 *   - Prefers the systemd user service when a usable user bus exists.
 *   - Falls back to running dockerd-rootless.sh directly when systemd user
 *     bus access fails, using the same XDG_RUNTIME_DIR and PATH tweaks that
 *     the watchdog and installer rely on.
 *   - Logs actions to the per-user PMSS log via pmssUserLog().
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/log.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: /scripts/util/dockerUserService.php USER {start|stop|restart|status}\n");
    exit(1);
}

$user = $argv[1];
$action = strtolower($argv[2]);
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

pmssUserLog($user, sprintf('dockerUserService: action=%s requested', $action));

// Helper to execute a command as the target user via su.
function dockerUserRunAs(string $user, string $cmd): string
{
    $wrapper = sprintf('su %s -c %s', escapeshellarg($user), escapeshellarg($cmd));
    $out = [];
    $rc = 0;
    exec($wrapper, $out, $rc);
    return implode("\n", $out);
}

// Detect whether we can talk to the systemd user service for this user.
$statusCmd = 'systemctl --user is-active docker.service 2>&1';
$rawStatus = trim(dockerUserRunAs($user, $statusCmd));
$hasUserBus = ($rawStatus !== '' && strpos($rawStatus, 'Failed to connect to bus') === false);
$serviceStatus = $hasUserBus ? $rawStatus : '';

// STATUS
if ($action === 'status') {
    if ($hasUserBus) {
        echo "systemd user service status: {$serviceStatus}\n";
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
    if ($hasUserBus) {
        pmssUserLog($user, 'dockerUserService: stopping via systemd user service');
        dockerUserRunAs($user, 'systemctl --user stop docker.service');
    } else {
        pmssUserLog($user, 'dockerUserService: stopping rootless daemon via pkill (no systemd user bus)');
        // Best-effort stop for non-systemd rootless: kill dockerd-rootless.sh/dockerd for this user.
        dockerUserRunAs($user, 'pkill -f dockerd-rootless.sh || pkill -f "dockerd-rootless" || pkill dockerd || true');
    }
    if ($action === 'stop') {
        echo "Docker stop requested for {$user}\n";
        exit(0);
    }
    // fall through to start for restart
}

// START
if ($action === 'start' || $action === 'restart') {
    if ($hasUserBus) {
        pmssUserLog($user, 'dockerUserService: starting via systemd user service');
        dockerUserRunAs($user, 'systemctl --user start docker.service');
        echo "Docker start requested for {$user} via systemd user service\n";
        exit(0);
    }

    // No usable systemd user bus: start dockerd-rootless.sh in the same way
    // the watchdog does, ensuring PATH and XDG_RUNTIME_DIR are sane.
    pmssUserLog($user, 'dockerUserService: starting rootless daemon via dockerd-rootless.sh (no systemd user bus)');
    $envCmd = sprintf(
        'XDG_RUNTIME_DIR=%s PATH=$PATH:/usr/sbin:/sbin:$HOME/bin nohup dockerd-rootless.sh >/dev/null 2>&1 &',
        "/run/user/{$uid}"
    );
    dockerUserRunAs($user, $envCmd);
    echo "Docker start requested for {$user} via dockerd-rootless.sh\n";
    exit(0);
}

// Should not be reached.
exit(0);

