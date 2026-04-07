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
require_once '/scripts/lib/userLifecycle.php';
require_once '/scripts/lib/user/userConfigStore.php';

$logger = new Logger(__FILE__);
// Mirror messages to the legacy logfile when stdout is interactive.
$mirrorLegacy = pmssStreamIsTty(STDOUT, true);

// Log both via the shared Logger and the historical cron redirect target.
$logDockerMessage = static function (string $message) use ($logger, $mirrorLegacy): void {
    $logger->msg($message);
    if ($mirrorLegacy) {
        pmssLogAppendTimestampedLine('/var/log/pmss/rootlessDocker.log', $message);
    }
};

$logDockerMessage('Checking rootless Docker services');
$users = pmssListManagedUsers();
$userConfigStore = new UserConfigStore();

foreach ($users as $user) {
    if (pmssUserWebRootUnavailable($user)) {
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
