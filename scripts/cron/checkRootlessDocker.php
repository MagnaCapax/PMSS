#!/usr/bin/php
<?php
// Ensure rootless Docker daemon is running for each user

require_once '/scripts/lib/logger.php';
require_once '/scripts/lib/runtime.php';
require_once '/scripts/lib/user/log.php';

$logger = new Logger(__FILE__);
$legacyLog = '/var/log/pmss/rootlessDocker.log';
// Mirror messages to the legacy logfile when stdout is interactive.
$mirrorLegacy = !function_exists('posix_isatty') || posix_isatty(STDOUT);

/**
 * Log both via the shared Logger and the historical cron redirect target.
 */
function logDockerMessage(string $message): void
{
    global $logger, $legacyLog, $mirrorLegacy;
    $logger->msg($message);
    if ($mirrorLegacy) {
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents($legacyLog, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

logDockerMessage('Checking rootless Docker services');

$users = explode("\n", trim(shell_exec('/scripts/listUsers.php')));

foreach ($users as $user) {
    if (empty($user)) continue;
    if (file_exists("/home/{$user}/www-disabled") || !file_exists("/home/{$user}/www")) {
        logDockerMessage("User {$user} is suspended");
        continue;
    }

    // Resolve UID for this user so we can derive the per-user runtime path.
    $uid = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
    if ($uid === '' || !ctype_digit($uid)) {
        logDockerMessage("Skipping {$user}: unable to resolve UID");
        continue;
    }
    $dockerSock = "/run/user/{$uid}/docker.sock";

    // First attempt to query systemd user instance; if the bus is not
    // accessible we fall back to checking the socket directly and starting
    // dockerd-rootless.sh without systemd.
    $statusCmd = sprintf(
        'su %s -c %s',
        escapeshellarg($user),
        escapeshellarg('systemctl --user is-active docker.service 2>&1')
    );
    $rawStatus = trim((string) shell_exec($statusCmd));
    $hasUserBus = ($rawStatus !== '' && strpos($rawStatus, 'Failed to connect to bus') === false);
    $status = $hasUserBus ? $rawStatus : '';

    if ($hasUserBus && $status === 'active') {
        logDockerMessage("Docker already running for {$user} via systemd user service");
        continue;
    }

    // If there is no usable systemd user bus but the Docker socket exists,
    // assume the daemon is already running in non-systemd rootless mode.
    if (!$hasUserBus && file_exists($dockerSock)) {
        logDockerMessage("Docker socket present for {$user} without systemd user bus; assuming running");
        continue;
    }

    if ($hasUserBus) {
        logDockerMessage("Starting Docker for {$user} via systemd user service");
        $startCmd = sprintf(
            "su %s -c %s >/dev/null 2>&1",
            escapeshellarg($user),
            escapeshellarg('systemctl --user start docker.service')
        );
        runCommand($startCmd, false, 'logDockerMessage');
        pmssUserLog($user, 'watchdog: systemctl --user start docker.service');
    } else {
        logDockerMessage("Starting rootless Docker for {$user} via dockerd-rootless.sh (no systemd user bus)");
        $inner = sprintf(
            'XDG_RUNTIME_DIR=%s PATH=$PATH:/usr/sbin:/sbin:$HOME/bin nohup dockerd-rootless.sh >/dev/null 2>&1 &',
            "/run/user/{$uid}"
        );
        $startCmd = sprintf(
            'su %s -c %s',
            escapeshellarg($user),
            escapeshellarg($inner)
        );
        runCommand($startCmd, false, 'logDockerMessage');
        pmssUserLog($user, 'watchdog: started dockerd-rootless.sh (no systemd user bus)');
    }
}
