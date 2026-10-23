<?php
/** Daemon-first shutdown with one checked scan boundary. Loaded by cli.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const USER_DOCKER_DAEMON_WAIT_SECONDS = 20;
const USER_DOCKER_STOP_DEADLINE_SECONDS = 30;

/** Select only this account's named daemon, namespace owner, and wrapper PIDs. */
function userDockerStopScan(string $user, int $uid, bool $initial = false): array
{
    $lines = [];
    $rc = 0;
    exec('ps -u '.escapeshellarg((string) $uid).' -o pid=,comm=,args= 2>&1', $lines, $rc);
    // ps exits 1 with no output when the account runs no processes (a parked old copy); that is a clean scan.
    if ($rc === 1 && $lines === []) $rc = 0;
    $pids = ['dockerd' => [], 'rootlesskit' => [], 'wrapper' => []];
    if ($rc === 0) foreach ($lines as $line) {
        if (!preg_match('/^\s*(\d+)\s+(\S+)\s+(.*)$/D', $line, $parts)) continue;
        $name = $parts[2];
        if ($name === 'dockerd' || $name === 'rootlesskit') {
            $pids[$name][] = (int) $parts[1];
        } elseif (preg_match('~^(?:(?:\S*/)?(?:bash|sh)\s+)?(?:\S*/)?dockerd-rootless\.sh(?:\s|$)~', $parts[3])) {
            $pids['wrapper'][] = (int) $parts[1];
        }
    }
    if ($initial) pmssUserLog($user, sprintf('userDocker: step=scan rc=%d count=%d', $rc, count($pids['dockerd'])));
    if ($rc !== 0) pmssCliExitWithStderr("Docker stop failed for {$user}: process check failed\n", 1);
    return $pids;
}

/** Stop and verify the account daemon before a restart may launch again. */
function userDockerStop(string $user, int $uid, string $runtimeDir, bool $serviceExists): void
{
    $userDockerStore = new UserConfigStore();
    $dockerEnabled = pmssUserDockerEnabled($user, $userDockerStore);
    $stopStarted = microtime(true);
    $stopPids = userDockerStopScan($user, $uid, true);
    userDockerStopSignal($user, $uid, $stopPids['dockerd'], 15, 'dockerd-term');

    // Give dockerd longer than its default shutdown timeout to stop containers.
    while ($stopPids['dockerd'] !== [] && microtime(true) - $stopStarted < USER_DOCKER_DAEMON_WAIT_SECONDS) {
        usleep(200000);
        $stopPids = userDockerStopScan($user, $uid);
    }
    pmssUserLog($user, sprintf('userDocker: step=dockerd-wait rc=%d count=%d',
        $stopPids['dockerd'] === [] ? 0 : 1, count($stopPids['dockerd'])));

    if ($serviceExists) {
        $stopRc = 0;
        $systemdAction = !$dockerEnabled ? 'disable --now' : 'stop';
        $systemdStopCmd = sprintf(
            'XDG_RUNTIME_DIR=%s systemctl --user %s docker.service',
            escapeshellarg($runtimeDir),
            $systemdAction
        );
        userDockerRunAs($user, $systemdStopCmd, 5, $stopRc);
        pmssUserLog($user, sprintf('userDocker: step=systemctl-stop rc=%d count=1', $stopRc));
    }
    $stopPids = userDockerStopScan($user, $uid);
    userDockerStopSignal($user, $uid, array_merge($stopPids['rootlesskit'], $stopPids['wrapper']), 15, 'namespace-term');

    while (microtime(true) - $stopStarted < USER_DOCKER_STOP_DEADLINE_SECONDS) {
        $stopPids = userDockerStopScan($user, $uid);
        if (array_merge($stopPids['dockerd'], $stopPids['rootlesskit'], $stopPids['wrapper']) === []) break;
        usleep(200000);
    }
    $remainingPids = array_merge($stopPids['dockerd'], $stopPids['rootlesskit'], $stopPids['wrapper']);
    if ($remainingPids !== []) {
        userDockerStopSignal($user, $uid, $remainingPids, 9, 'remaining-kill');
        usleep(200000);
        $stopPids = userDockerStopScan($user, $uid);
        $remainingPids = array_merge($stopPids['dockerd'], $stopPids['rootlesskit'], $stopPids['wrapper']);
    }
    pmssUserLog($user, sprintf('userDocker: step=verify rc=%d count=%d', $remainingPids === [] ? 0 : 1, count($remainingPids)));
    if ($remainingPids !== []) {
        $detail = 'remaining pid(s): '.implode(', ', $remainingPids);
        $failure = sprintf('Docker stop failed for %s: %s', $user, $detail);
        pmssUserLog($user, '[ERR] '.$failure);
        pmssCliExitWithStderr($failure."\n", 1);
    }
}
