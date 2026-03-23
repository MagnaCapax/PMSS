<?php
/**
 * Nginx configuration test + optional restart.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../runtime.php';

function pmssCreateNginxConfigTestAndMaybeRestart(bool $restartNginx): int
{
    // Validate nginx configuration before any restart attempt.
    // Always run the test to give operators visibility into config health.
    // Safety: never restart nginx with broken config. If test fails:
    // - Show CRITICAL error with full nginx -t output
    // - Log to /var/log/pmss/update.log for post-mortem
    // - Exit with rc=1 so callers can detect failure
    // - Refuse to restart even if --restart was requested
    // #TODO: Consider adding JSON logging (pmssLogJson) once this script is
    // integrated with the update runtime that provides those helpers.
    $configTestOutput = [];
    $configTestRc = 0;
    exec('nginx -t 2>&1', $configTestOutput, $configTestRc);
    $configTestResult = implode("\n", $configTestOutput);

    // ANSI colors for CLI output (stripped by logMessage for file logging).
    $isTty = pmssStreamIsTty(STDOUT);
    $cReset  = $isTty ? "\033[0m"  : '';
    $cRed    = $isTty ? "\033[31m" : '';
    $cGreen  = $isTty ? "\033[32m" : '';
    $cYellow = $isTty ? "\033[33m" : '';

    // Log to PMSS standard log if available.
    $logFile = '/var/log/pmss/update.log';
    $logTs = date('[Y-m-d H:i:s] ');
    $logPrefix = '[createNginxConfig] ';

    if ($configTestRc === 0) {
        $statusMsg = "{$cGreen}[OK]{$cReset} nginx configuration test passed";
        echo $statusMsg."\n";
        @file_put_contents($logFile, $logTs.$logPrefix."nginx -t passed (rc=0)\n", FILE_APPEND | LOCK_EX);
    } else {
        // Critical error: config is broken.
        $criticalMsg = "{$cRed}[CRITICAL]{$cReset} nginx configuration test {$cRed}FAILED{$cReset} (rc={$configTestRc})";
        echo $criticalMsg."\n";
        echo "{$cRed}{$configTestResult}{$cReset}\n";
        @file_put_contents($logFile, $logTs.$logPrefix."CRITICAL: nginx -t failed (rc={$configTestRc}): {$configTestResult}\n", FILE_APPEND | LOCK_EX);
    }

    if ($restartNginx) {
        if ($configTestRc === 0) {
            passthru('systemctl restart nginx || /etc/init.d/nginx restart || true');
            echo "## Done! nginx restarted\n";
            @file_put_contents($logFile, $logTs.$logPrefix."nginx restarted\n", FILE_APPEND | LOCK_EX);
        } else {
            echo "{$cRed}## Restart aborted: refusing to restart nginx with broken configuration{$cReset}\n";
            echo "## Fix the errors above, then manually restart:\n";
            echo "   systemctl restart nginx\n";
            @file_put_contents($logFile, $logTs.$logPrefix."restart aborted due to config test failure\n", FILE_APPEND | LOCK_EX);
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
