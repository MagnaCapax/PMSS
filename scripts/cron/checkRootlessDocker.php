#!/usr/bin/env php
<?php
/**
 * Cron task: check Rootless Docker.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Ensure rootless Docker daemon is running for each user

require_once '/scripts/lib/logger.php';
require_once '/scripts/lib/runtime.php';
require_once '/scripts/lib/user/log.php';
require_once '/scripts/lib/user/userConfigStore.php';

$logger = new Logger(__FILE__);
// Mirror messages to the legacy logfile when stdout is interactive.
$mirrorLegacy = !function_exists('posix_isatty') || posix_isatty(STDOUT);

// Log both via the shared Logger and the historical cron redirect target.
$logDockerMessage = static function (string $message) use ($logger, $mirrorLegacy): void {
    $logger->msg($message);
    if ($mirrorLegacy) {
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents('/var/log/pmss/rootlessDocker.log', $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
};

$logDockerMessage('Checking rootless Docker services');

$users = array_filter(explode("\n", trim(shell_exec('/scripts/listUsers.php'))));
$userConfigStore = new UserConfigStore();

foreach ($users as $user) {
    if (file_exists("/home/{$user}/www-disabled") || !file_exists("/home/{$user}/www")) {
        $logDockerMessage("User {$user} is suspended");
        continue;
    }
    if (function_exists('pmssUserDockerEnabled') && !pmssUserDockerEnabled($user, $userConfigStore)) {
        $logDockerMessage("User {$user}: Docker disabled by config; skipping");
        continue;
    }

    // Delegate start logic to userDocker helper so systemd and non-systemd
    // rootless modes are handled consistently and logged per user.
    $cmd = sprintf('php /scripts/util/userDocker.php %s start', escapeshellarg($user));
    runCommand($cmd, false, $logDockerMessage);
}
