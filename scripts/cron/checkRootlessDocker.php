#!/usr/bin/env php
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

    // Delegate start logic to userDocker helper so systemd and non-systemd
    // rootless modes are handled consistently and logged per user.
    $cmd = sprintf('php /scripts/util/userDocker.php %s start', escapeshellarg($user));
    runCommand($cmd, false, 'logDockerMessage');
}
