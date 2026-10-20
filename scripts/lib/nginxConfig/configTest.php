<?php
/**
 * Nginx configuration test + optional restart.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime.php';

/**
 * Read an nginx helper command override without accepting blank or multiline values.
 */
function pmssCreateNginxConfigCommandFromEnv(string $envKey, string $default): string
{
    $command = pmssEnvTrimmed($envKey);
    if ($command === '' || strpos($command, "\0") !== false || strpos($command, "\n") !== false || strpos($command, "\r") !== false) {
        return $default;
    }

    return $command;
}

function pmssCreateNginxConfigAppendLog(string $message): void
{
    $logFile = pmssLogDir().'/update.log';
    $logDir = dirname($logFile);
    pmssDirEnsureExists($logDir, 0755);

    pmssLogAppendTimestampedLine($logFile, $message, '[Y-m-d H:i:s] ', '[createNginxConfig] ');
}

function pmssCreateNginxConfigTestAndMaybeRestart(bool $restartNginx, bool $reloadNginx = false): int
{
    $configTestOutput = [];
    exec(pmssCreateNginxConfigCommandFromEnv('PMSS_NGINX_CONFIG_TEST_COMMAND', 'nginx -t 2>&1'), $configTestOutput, $configTestRc);
    $configTestResult = implode("\n", $configTestOutput);

    $isTty = pmssStreamIsTty(STDOUT);
    $cReset  = $isTty ? "\033[0m"  : '';
    $cRed    = $isTty ? "\033[31m" : '';
    $cGreen  = $isTty ? "\033[32m" : '';
    $cYellow = $isTty ? "\033[33m" : '';

    if ($configTestRc === 0) {
        echo "{$cGreen}[OK]{$cReset} nginx configuration test passed\n";
        pmssCreateNginxConfigAppendLog('nginx -t passed (rc=0)');
    } else {
        echo "{$cRed}[CRITICAL]{$cReset} nginx configuration test {$cRed}FAILED{$cReset} (rc={$configTestRc})\n";
        echo "{$cRed}{$configTestResult}{$cReset}\n";
        pmssCreateNginxConfigAppendLog("CRITICAL: nginx -t failed (rc={$configTestRc}): {$configTestResult}");

        if ($restartNginx) {
            echo "{$cRed}## Restart aborted: refusing to restart nginx with broken configuration{$cReset}\n## Fix the errors above, then manually restart:\n   systemctl restart nginx\n";
            pmssCreateNginxConfigAppendLog('restart aborted due to config test failure');
        } elseif ($reloadNginx) {
            echo "{$cRed}## Reload aborted: refusing to reload nginx with broken configuration{$cReset}\n";
            pmssCreateNginxConfigAppendLog('reload aborted due to config test failure');
        } else {
            echo "{$cYellow}## Fix the configuration errors above before restarting nginx{$cReset}\n";
        }

        return 1;
    }

    if (!$restartNginx && !$reloadNginx) {
        echo "## Done! You should restart nginx:\n   systemctl restart nginx\n";
        return 0;
    }

    // A fresh install may have no running master yet; reload also tries start.
    $operation = $reloadNginx ? 'reload/start' : 'restart';
    $command = $reloadNginx
        ? pmssCreateNginxConfigCommandFromEnv('PMSS_NGINX_RELOAD_COMMAND', 'systemctl reload nginx || /etc/init.d/nginx reload || systemctl start nginx || /etc/init.d/nginx start')
        : pmssCreateNginxConfigCommandFromEnv('PMSS_NGINX_RESTART_COMMAND', 'systemctl restart nginx || /etc/init.d/nginx restart');
    $actionRc = 0;
    passthru($command, $actionRc);
    if ($actionRc !== 0) {
        echo "{$cRed}[CRITICAL]{$cReset} nginx {$operation} {$cRed}FAILED{$cReset} (rc={$actionRc})\n";
        pmssCreateNginxConfigAppendLog("CRITICAL: nginx {$operation} failed (rc={$actionRc})");
        return 1;
    }

    $done = $reloadNginx ? 'nginx reloaded or started' : 'nginx restarted';
    echo "## Done! {$done}\n";
    pmssCreateNginxConfigAppendLog($done);

    return 0;
}
