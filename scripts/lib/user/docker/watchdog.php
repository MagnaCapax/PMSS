<?php
/** Rootless Docker version-drift decisions for checkRootlessDocker.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Select a restart only after successful version and idle-container observations. */
function userDockerWatchdogAction(string $user, callable $log): ?string
{
    $info = userDockerAccountInfo($user);
    if ($info === null) {
        $log('Docker watchdog: invalid account; skipping');
        return null;
    }
    if (pmssUserWatchdogBackoffActive($user, 'docker')) {
        $log("Docker watchdog for {$user}: probe timeout backoff; skipping");
        return null;
    }

    $runtimeDir = '/run/user/'.(int) $info['uid'];
    if (!file_exists($runtimeDir.'/docker.sock')) return 'start';

    // Account identity, process-group deadlines and inherited lock FDs use shared helpers.
    $probe = static function (string $command) use ($user): array {
        return pmssUserWatchdogCommandRun(pmssBuildUserShellCommand($user, $command), 5, $user, 'docker');
    };
    // An explicit host prevents an account's selected Docker context choosing a remote daemon.
    $docker = 'XDG_RUNTIME_DIR='.escapeshellarg($runtimeDir).' /usr/bin/docker --host='
        .escapeshellarg('unix://'.$runtimeDir.'/docker.sock').' ';
    $server = $probe($docker."version --format '{{.Server.Version}}'");
    $versionPattern = '/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/';
    $running = trim($server['stdout']);
    if ($server['rc'] !== 0 || preg_match($versionPattern, $running) !== 1) {
        $log("Docker watchdog for {$user}: event=version_probe_failed rc={$server['rc']}; retaining start path");
        return 'start';
    }

    $installed = $probe('/usr/bin/dockerd --version');
    if ($installed['rc'] !== 0
        || preg_match('/\ADocker version ([^,\s]+),/', trim($installed['stdout']), $match) !== 1
        || preg_match($versionPattern, $match[1]) !== 1) {
        $log("Docker watchdog for {$user}: event=installed_version_probe_failed rc={$installed['rc']}; retaining start path");
        return 'start';
    }
    if ($running === $match[1]) return 'start';

    // Failure or any listed container defers recovery; never infer idle from a failed probe.
    $containers = $probe($docker.'ps --quiet');
    $idle = $containers['rc'] === 0 && trim($containers['stdout']) === '';
    $action = $idle ? 'restart' : 'start';
    $reason = $idle ? 'idle' : ($containers['rc'] === 0 ? 'running_containers' : 'container_probe_failed');
    $log("Docker watchdog for {$user}: event=version_drift server={$running} installed={$match[1]} decision={$action} reason={$reason} rc={$containers['rc']}");
    return $action;
}
