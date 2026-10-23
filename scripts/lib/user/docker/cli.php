<?php
/** Stable CLI validation and lifecycle dispatch for the userDocker entrypoint.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../cli/optionParser.php';
require_once __DIR__.'/../../runtime.php';
pmssRequireRelativeFiles(__DIR__, [
    '../serviceLaunch.php', '../../userLifecycle.php', '../log.php', '../rootlessDockerConfig.php',
    '../userConfigStore.php', '../dockerContainerIds.php',
    'command.php', 'process.php', 'stop.php', 'start.php', 'containers.php', 'status.php',
]);

/** Validate CLI inputs and the positive-UID account before deriving runtime paths. */
function userDockerMain(array $argv): int
{
    [$debug, $args] = pmssCliArgvDebugSplit($argv ?? null);

    if (count($args) < 3) {
        pmssCliExitWithStderr("Usage: /scripts/util/userDocker.php USER {start|stop|restart|status|start-containers} [ID ...] [--debug]\n", 1);
    }

    $user = $args[1];
    $action = strtolower($args[2]);
    $valid = ['start', 'stop', 'restart', 'status', 'start-containers'];
    if (!in_array($action, $valid, true)) {
        pmssCliExitWithStderr("Invalid action: {$action}\nSupported actions: start, stop, restart, status, start-containers\n", 1);
    }
    if ($action === 'start-containers' && !pmssUserDockerContainerIdsValid(array_slice($args, 3))) {
        pmssCliExitWithStderr("Invalid container ID list for start-containers\n", 1);
    }

    // Resolve UID so we can derive runtime paths.
    $accountReason = null;
    $info = userDockerAccountInfo($user, $accountReason);
    if ($info === null) {
        $safeUser = pmssLogSafeLabel($user);
        if ($accountReason === 'invalid') {
            fwrite(STDERR, "Invalid user: {$safeUser}\n");
        } elseif ($accountReason === 'unsafe_uid') {
            fwrite(STDERR, "Unsafe user UID: {$safeUser}\n");
        } else {
            fwrite(STDERR, "Unknown user: {$safeUser}\n");
        }
        return 1;
    }
    $uid = (int) $info['uid'];
    $runtimeDir = "/run/user/{$uid}";
    $dockerSock = $runtimeDir.'/docker.sock';
    $home = isset($info['dir']) ? (string) $info['dir'] : '';
    $dockerUnitPath = $home !== '' ? $home.'/.config/systemd/user/docker.service' : '';
    $serviceExists = ($dockerUnitPath !== '' && is_file($dockerUnitPath));

    if ($debug) {
        pmssUserLog($user, sprintf('userDocker: action=%s requested', $action));
    }
    if ($action === 'start-containers') return userDockerStartContainers($user, $runtimeDir, $dockerSock, array_slice($args, 3));

    // Keep the initial observation before dispatch, including the second check on start/restart.
    $processCheckOk = true;
    $dockerPids = userDockerCollectPids($user, $debug, $processCheckOk);
    if ($action === 'status') return userDockerStatus($user, $home, $runtimeDir, $dockerSock, $dockerUnitPath, $serviceExists, $dockerPids, $processCheckOk, $debug);
    if ($action === 'stop' || $action === 'restart') {
        userDockerStop($user, $uid, $runtimeDir, $serviceExists);
        if ($action === 'stop') {
            echo "Docker stop requested for {$user}\n";
            return 0;
        }
    }
    return userDockerStart($user, $info, $runtimeDir, $dockerSock, $debug);
}
