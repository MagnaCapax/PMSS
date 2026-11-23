<?php
/** Rootless Docker version-drift decisions for checkRootlessDocker.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/process.php';
require_once dirname(__DIR__).'/watchdogLaunch.php';

/** Seconds a launch marker stays meaningful: six 5-minute ticks. */
const PMSS_DOCKER_LAUNCH_MARKER_MAX_AGE = 1800;

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

/**
 * Surface a daemon that died after a successful launch (the launch itself always reports OK).
 *
 * Call before the start decision: a running daemon clears the launch marker; an absent one with a
 * marker left by the previous tick is logged as a post-launch death. Relaunch is never suppressed,
 * and the first launch after boot or enablement stays silent. An unreadable process list is no evidence.
 */
function userDockerWatchdogLaunchObserve(string $user, callable $log): void
{
    $checkOk = true;
    $pids = userDockerCollectPids($user, false, $checkOk);
    if (!$checkOk) return;
    if ($pids !== []) {
        pmssUserWatchdogLaunchMarkerClear($user, 'docker');
        return;
    }
    $launched = pmssUserWatchdogLaunchMarkerRead($user, 'docker');
    // A marker older than a few ticks is stale (account disabled or parked since), not a death.
    if ($launched !== null && time() - $launched <= PMSS_DOCKER_LAUNCH_MARKER_MAX_AGE) {
        $log("[WARN] Docker watchdog for {$user}: event=daemon_died_after_launch launched_at={$launched}; "
            .'dockerd-rootless.sh was started on the previous tick and is not running now; relaunching');
        pmssUserLog($user, '[WARN] userDocker: daemon died after launch; the watchdog relaunches it every tick, run dockerd-rootless.sh in the foreground to see why');
    }
    pmssUserWatchdogLaunchMarkerWrite($user, 'docker');
}
