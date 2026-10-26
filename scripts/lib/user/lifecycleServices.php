<?php
/** User lifecycle command steps, nginx refresh, and web-stack verification.
 * @license GPL-3.0-only
 */

/**
 * Execute a shell command for a user lifecycle step and log the outcome.
 */
function pmssUserLifecycleStep(string $action, string $username, string $step, string $command, bool $dryRun): int
{
    $started = microtime(true);
    $rc = 0;
    $duration = 0.0;
    if (!$dryRun) {
        @passthru($command, $rc);
        $duration = microtime(true) - $started;
    }

    pmssUserLifecycleContextLog($action, $step, $username, array(
        'step'     => $step,
        'command'  => $command,
        'rc'       => $rc,
        'duration' => round($duration, 4),
        'dry_run'  => $dryRun,
        'status'   => $dryRun ? 'SKIP' : ($rc === 0 ? 'OK' : 'ERR'),
    ));

    if ($dryRun) {
        echo "[DRY-RUN][{$action}] {$step}: {$command}\n";
    }

    return $rc;
}

/**
 * Run an ordered table of lifecycle shell steps through the shared logger.
 *
 * @param array<int,array{0:string,1:string}> $steps
 * @return array<string,int>
 */
function pmssUserLifecycleRunSteps(string $action, string $username, array $steps, bool $dryRun): array
{
    $results = array();
    foreach ($steps as $stepSpec) {
        $results[(string) $stepSpec[0]] = pmssUserLifecycleStep($action, $username, (string) $stepSpec[0], (string) $stepSpec[1], $dryRun);
    }
    return $results;
}

/** @param array<string,string> $restartOptions */
function pmssUserLifecycleRefreshNginxConfig(string $action, string $username, bool $dryRun, string $configStep, string $configCommand, array $restartOptions = array(), ?callable $stepRunner = null): int
{
    $runner = $stepRunner ?? 'pmssUserLifecycleStep';
    $systemctlStep = (string) ($restartOptions['systemctlStep'] ?? 'restart_nginx_systemctl');
    $systemctlCommand = (string) ($restartOptions['systemctlCommand'] ?? 'systemctl restart nginx');
    $initStep = (string) ($restartOptions['initStep'] ?? 'restart_nginx_init');
    $initCommand = (string) ($restartOptions['initCommand'] ?? '/etc/init.d/nginx restart');

    $configRc = (int) $runner($action, $username, $configStep, $configCommand, $dryRun);
    if ($configRc !== 0) {
        return $configRc;
    }

    $restartRc = (int) $runner($action, $username, $systemctlStep, $systemctlCommand, $dryRun);
    return $restartRc === 0 ? 0 : (int) $runner($action, $username, $initStep, $initCommand, $dryRun);
}

/**
 * Refresh the canonical per-user nginx config and restart nginx.
 */
function pmssUserLifecycleRefreshManagedNginxConfig(string $action, string $username, bool $dryRun, ?callable $stepRunner = null): int
{
    return pmssUserLifecycleRefreshNginxConfig(
        $action,
        $username,
        $dryRun,
        'refresh_nginx_config',
        'php /scripts/util/createNginxConfig.php --user '.escapeshellarg($username),
        array(),
        $stepRunner
    );
}

/**
 * Start the per-user web stack and require both daemon layers to remain up.
 */
function pmssUserLifecycleWebStackStartAndVerify(string $action, string $username, bool $dryRun, ?callable $stepRunner = null, ?callable $processChecker = null): int
{
    $runner = $stepRunner ?? 'pmssUserLifecycleStep';
    $startRc = (int) $runner(
        $action,
        $username,
        'start_lighttpd',
        '/scripts/startLighttpd '.escapeshellarg($username),
        $dryRun
    );
    if ($dryRun) {
        return $startRc;
    }

    // Observed state wins over the launcher rc when the watchdog won the race.
    $checker = $processChecker ?? 'pmssUserWatchdogProcessRunning';
    $missingProcesses = array();
    foreach (array('lighttpd', 'php-cgi') as $processName) {
        if (!(bool) $checker($username, $processName)) {
            $missingProcesses[] = $processName;
        }
    }
    // Project the single observed process set to both the audit and return status.
    $running = $missingProcesses === array();
    pmssUserLifecycleContextLogStatusMessage(
        $action,
        'verify_web_stack',
        $username,
        $running ? 'OK' : 'ERR',
        $running ? 'Per-user lighttpd and php-cgi are running' : 'Per-user web stack failed to start',
        array('start_rc' => $startRc) + ($running ? array() : array('missing_processes' => $missingProcesses))
    );
    return $running ? 0 : ($startRc !== 0 ? $startRc : 1);
}
