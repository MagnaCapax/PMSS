<?php
/**
 * addUser: conservative recovery for recent failed provisioning attempts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../userLifecycle.php';

/**
 * Keep failed-provision cleanup scoped to one validated /home target.
 */
function pmssAddUserCleanupFailedProvisionTargetValid(string $userName, string $homePath): bool
{
    if (!pmssValidateUsername($userName)) {
        return false;
    }

    if ($homePath === '' || strpos($homePath, "\0") !== false) {
        return false;
    }

    return $homePath === '/home/'.$userName;
}

/**
 * Return the most recent structured addUser summary for one username.
 *
 * @return array<string,mixed>|null
 */
function pmssAddUserLatestProvisionSummary(string $userName): ?array
{
    $logPath = function_exists('pmssAddUserProvisioningLogPath')
        ? pmssAddUserProvisioningLogPath()
        : pmssResolvePathFromEnv('PMSS_ADDUSER_LOG_PATH', '/var/log/pmss/addUser.log');
    if (!is_file($logPath) || is_link($logPath)) {
        return null;
    }

    $summary = null;
    $handle = @fopen($logPath, 'r');
    if (!is_resource($handle)) {
        return null;
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $markerPos = strpos($line, '###ADDUSER_JSON:');
            if ($markerPos === false) {
                continue;
            }
            $payload = pmssJsonDecodeAssoc(substr($line, $markerPos + 16));
            if ($payload === null || ($payload['event'] ?? '') !== 'adduser_summary' || ($payload['user'] ?? '') !== $userName) {
                continue;
            }
            $payload['timestamp'] = strtotime(substr($line, 0, 19)) ?: 0;
            $summary = $payload;
        }
    } finally {
        fclose($handle);
    }

    return $summary;
}

/**
 * Keep orphan recovery limited to fresh internal failures, never guard errors.
 */
function pmssAddUserProvisionSummaryRecoverable(?array $summary, ?int $now = null): bool
{
    if (!is_array($summary) || ($summary['status'] ?? '') !== 'FAIL') {
        return false;
    }

    $timestamp = (int) ($summary['timestamp'] ?? 0);
    if ($timestamp <= 0) {
        return false;
    }

    $now = $now ?? time();
    $window = (int) (getenv('PMSS_ADDUSER_RECOVERY_WINDOW_SECONDS') ?: 3600);
    if ($window < 60) {
        $window = 60;
    }
    if ($window > 86400) {
        $window = 86400;
    }

    return ($now - $timestamp) <= $window;
}

/**
 * Self-heal only when the latest run failed internally and no services are live.
 */
function pmssAddUserFailedProvisionCanRecover(string $userName, ?array $summary = null, ?array $serviceState = null, ?int $now = null): bool
{
    $summary = $summary ?? pmssAddUserLatestProvisionSummary($userName);
    if (!pmssAddUserProvisionSummaryRecoverable($summary, $now)) {
        return false;
    }

    if ($serviceState === null) {
        $serviceState = array(
            'rtorrent' => pmssUserWatchdogProcessRunning($userName, 'rtorrent'),
            'lighttpd' => pmssUserWatchdogProcessRunning($userName, 'lighttpd'),
        );
    }

    return empty($serviceState['rtorrent']) && empty($serviceState['lighttpd']);
}

/**
 * Remove stale resources from a known failed provisioning run.
 */
function pmssAddUserCleanupFailedProvision(users $userDb, string $userName, string $homePath): bool
{
    if (!pmssAddUserCleanupFailedProvisionTargetValid($userName, $homePath)) {
        logProvisionMessage('Refusing failed provisioning cleanup due to invalid target scope');
        return false;
    }

    $cleanupSteps = array(
        array('Kill lingering user processes', 'killall -9 -u '.escapeshellarg($userName).' || true'),
        array('Release lighttpd port', '/scripts/util/portManager.php release '.escapeshellarg($userName).' lighttpd'),
        array('Remove nginx user config', 'rm -f -- '.escapeshellarg('/etc/nginx/users/'.$userName)),
        array('Remove runtime traffic limit', 'rm -f -- '.escapeshellarg('/etc/seedbox/runtime/trafficLimits/'.$userName)),
        array('Remove runtime IOPS limit', 'rm -f -- '.escapeshellarg('/etc/seedbox/runtime/iopsLimits/'.$userName).' '.escapeshellarg('/etc/seedbox/runtime/iopsLimitEnforced/'.$userName)),
        array('Delete user account and home', 'userdel -r '.escapeshellarg($userName).' || true'),
        array('Delete leftover home directory', 'rm -rf -- '.escapeshellarg($homePath)),
        array('Delete user group', 'groupdel '.escapeshellarg($userName).' || true'),
        array('Remove screen socket', 'rm -rf -- '.escapeshellarg('/var/run/screen/S-'.$userName)),
        array('Cleanup addUser lock files', 'rm -f -- '.escapeshellarg('/run/lock/pmss-addUser-'.$userName.'.lock').' '.escapeshellarg('/tmp/pmss-addUser-'.$userName.'.lock')),
    );

    foreach ($cleanupSteps as $step) {
        runProvisionStep($step[0], $step[1]);
    }

    if (pmssUserAccountLookup($userName) !== null || is_dir($homePath)) {
        return false;
    }

    $userDb->removeUser($userName);
    logProvisionMessage('Removed stale user database entry');
    return true;
}
