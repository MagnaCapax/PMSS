<?php
/** Readiness and explicit transferred-container startup. Loaded by cli.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Wait for the daemon, then start only the already-validated transferred set. */
function userDockerStartContainers(string $user, string $runtimeDir, string $dockerSock, array $containerIds): int
{
    $userConfigStore = new UserConfigStore();
    if (!pmssUserDockerEnabled($user, $userConfigStore)) {
        pmssUserLog($user, sprintf('userDocker: start-containers blocked; Docker disabled by config or RAM floor (%d MiB)', pmssUserDockerMinRamMiB()));
        echo "Docker start blocked for {$user}: Docker is disabled or RAM is below ".pmssUserDockerMinRamMiB()." MiB\n";
        return 0;
    }

    $dockerEnv = 'XDG_RUNTIME_DIR='.escapeshellarg($runtimeDir)
        .' DOCKER_HOST='.escapeshellarg('unix://'.$dockerSock).' docker ';
    $deadline = time() + 60;
    do {
        $infoRc = 0;
        userDockerRunAs($user, $dockerEnv.'info >/dev/null 2>&1', 5, $infoRc);
        if ($infoRc === 0) {
            break;
        }
        if (time() >= $deadline) {
            pmssUserLog($user, '[WARN] userDocker: Docker not ready for container start');
            pmssCliExitWithStderr("Docker not ready for {$user}; containers not started\n", 1);
        }
        sleep(min(2, max(1, $deadline - time())));
    } while (true);

    $startRc = 0;
    $output = userDockerRunAs($user, $dockerEnv.'start '.implode(' ', array_map('escapeshellarg', $containerIds)).' 2>/dev/null', 120, $startRc);
    $started = $output === '' ? 0 : min(count($containerIds), count(explode("\n", $output)));
    $statesRc = 0;
    $states = userDockerRunAs($user, $dockerEnv."ps -a --format '{{.State}}'", 30, $statesRc);
    if ($statesRc === 0) {
        $counts = array_count_values(preg_grep('/^[a-z]+$/D', explode("\n", $states)));
        ksort($counts);
        $parts = [];
        foreach ($counts as $state => $count) {
            $parts[] = $state.'='.$count;
        }
        pmssUserLog($user, 'userDocker: containers by state: '.implode(' ', $parts));
    }
    echo sprintf("Started %d of %d container(s) for %s\n", $started, count($containerIds), $user);
    return $startRc === 0 ? 0 : 1;
}
