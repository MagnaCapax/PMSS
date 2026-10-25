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
    $command = (string) getenv($envKey);
    // Validate original bytes before trim can hide a malformed command boundary.
    if (strpos($command, "\0") !== false || strpos($command, "\n") !== false || strpos($command, "\r") !== false) {
        return $default;
    }

    $command = trim($command);
    return $command === '' ? $default : $command;
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

    if ($configTestRc === 0) {
        echo pmssCliAnsiStyle('[OK]', '32', $isTty)." nginx configuration test passed\n";
        pmssCreateNginxConfigAppendLog('nginx -t passed (rc=0)');
    } else {
        echo pmssCliAnsiStyle('[CRITICAL]', '31', $isTty).' nginx configuration test '.pmssCliAnsiStyle('FAILED', '31', $isTty)." (rc={$configTestRc})\n";
        echo pmssCliAnsiStyle($configTestResult, '31', $isTty)."\n";
        pmssCreateNginxConfigAppendLog("CRITICAL: nginx -t failed (rc={$configTestRc}): {$configTestResult}");

        if ($restartNginx) {
            echo pmssCliAnsiStyle('## Restart aborted: refusing to restart nginx with broken configuration', '31', $isTty)."\n## Fix the errors above, then manually restart:\n   systemctl restart nginx\n";
            pmssCreateNginxConfigAppendLog('restart aborted due to config test failure');
        } elseif ($reloadNginx) {
            echo pmssCliAnsiStyle('## Reload aborted: refusing to reload nginx with broken configuration', '31', $isTty)."\n";
            pmssCreateNginxConfigAppendLog('reload aborted due to config test failure');
        } else {
            echo pmssCliAnsiStyle('## Fix the configuration errors above before restarting nginx', '33', $isTty)."\n";
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
        echo pmssCliAnsiStyle('[CRITICAL]', '31', $isTty)." nginx {$operation} ".pmssCliAnsiStyle('FAILED', '31', $isTty)." (rc={$actionRc})\n";
        pmssCreateNginxConfigAppendLog("CRITICAL: nginx {$operation} failed (rc={$actionRc})");
        return 1;
    }

    $done = $reloadNginx ? 'nginx reloaded or started' : 'nginx restarted';
    echo "## Done! {$done}\n";
    pmssCreateNginxConfigAppendLog($done);

    return 0;
}
