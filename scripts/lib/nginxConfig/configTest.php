<?php
/**
 * Nginx configuration test + optional restart.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime.php';

/**
 * Read an nginx helper command override without accepting blank values.
 */
function pmssCreateNginxConfigCommandFromEnv(string $envKey, string $default): string
{
    $command = getenv($envKey);
    if (!is_string($command)) {
        return $default;
    }

    $command = trim($command);
    return $command !== '' ? $command : $default;
}

function pmssCreateNginxConfigAppendLog(string $message): void
{
    $logFile = pmssLogDir().'/update.log';
    $logDir = dirname($logFile);
    pmssDirEnsureExists($logDir, 0755);

    @file_put_contents($logFile, date('[Y-m-d H:i:s] ').'[createNginxConfig] '.$message."\n", FILE_APPEND | LOCK_EX);
}

function pmssCreateNginxConfigTestAndMaybeRestart(bool $restartNginx): int
{
    $configTestOutput = [];
    $configTestRc = 0;
    exec(pmssCreateNginxConfigCommandFromEnv('PMSS_NGINX_CONFIG_TEST_COMMAND', 'nginx -t 2>&1'), $configTestOutput, $configTestRc);
    $configTestResult = implode("\n", $configTestOutput);

    $isTty = pmssStreamIsTty(STDOUT);
    $cReset  = $isTty ? "\033[0m"  : '';
    $cRed    = $isTty ? "\033[31m" : '';
    $cGreen  = $isTty ? "\033[32m" : '';
    $cYellow = $isTty ? "\033[33m" : '';

    if ($configTestRc === 0) {
        $statusMsg = "{$cGreen}[OK]{$cReset} nginx configuration test passed";
        echo $statusMsg."\n";
        pmssCreateNginxConfigAppendLog('nginx -t passed (rc=0)');
    } else {
        $criticalMsg = "{$cRed}[CRITICAL]{$cReset} nginx configuration test {$cRed}FAILED{$cReset} (rc={$configTestRc})";
        echo $criticalMsg."\n";
        echo "{$cRed}{$configTestResult}{$cReset}\n";
        pmssCreateNginxConfigAppendLog("CRITICAL: nginx -t failed (rc={$configTestRc}): {$configTestResult}");
    }

    if ($restartNginx) {
        if ($configTestRc === 0) {
            $restartRc = 0;
            passthru(pmssCreateNginxConfigCommandFromEnv('PMSS_NGINX_RESTART_COMMAND', 'systemctl restart nginx || /etc/init.d/nginx restart'), $restartRc);
            if ($restartRc !== 0) {
                echo "{$cRed}[CRITICAL]{$cReset} nginx restart {$cRed}FAILED{$cReset} (rc={$restartRc})\n";
                pmssCreateNginxConfigAppendLog("CRITICAL: nginx restart failed (rc={$restartRc})");
                return 1;
            }

            echo "## Done! nginx restarted\n";
            pmssCreateNginxConfigAppendLog('nginx restarted');
        } else {
            echo "{$cRed}## Restart aborted: refusing to restart nginx with broken configuration{$cReset}\n";
            echo "## Fix the errors above, then manually restart:\n";
            echo "   systemctl restart nginx\n";
            pmssCreateNginxConfigAppendLog('restart aborted due to config test failure');
            return 1;
        }
    } else {
        if ($configTestRc === 0) {
            echo "## Done! You should restart nginx:\n";
            echo "   systemctl restart nginx\n";
        } else {
            echo "{$cYellow}## Fix the configuration errors above before restarting nginx{$cReset}\n";
            return 1;
        }
    }

    return 0;
}
