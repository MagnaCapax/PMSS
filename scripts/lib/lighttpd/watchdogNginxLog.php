<?php
/**
 * Nginx upstream-failure evidence for the per-user lighttpd watchdog.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/user/identity.php';

if (!defined('PMSS_LIGHTTPD_WATCHDOG_NGINX_FAILURE_CYCLES')) {
    define('PMSS_LIGHTTPD_WATCHDOG_NGINX_FAILURE_CYCLES', 3);
}

/** Parse the PMSS suffix from one nginx access-log line. */
function pmssLighttpdWatchdogNginxEventParse(string $line): ?array
{
    $pattern = '/ pmss_status="([0-9]{3})"'
        .' pmss_upstream_addr="127\.0\.0\.1:([0-9]{1,5})"'
        .' pmss_upstream_status="([0-9]{3})"'
        .' pmss_upstream_header_time="([0-9.]+|-)"\s*$/';
    if (preg_match($pattern, $line, $matches) !== 1) {
        return null;
    }

    $port = (int) $matches[2];
    if (!pmssNetworkPortInRange($port)) {
        return null;
    }

    // A numeric header time proves lighttpd answered, even when its own
    // downstream application returned 502. Only no-header 502s implicate this hop.
    $headerReceived = preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $matches[4]) === 1;
    if ($headerReceived) {
        return array('port' => $port, 'outcome' => 'healthy');
    }
    if ($matches[1] === '502' && $matches[3] === '502') {
        return array('port' => $port, 'outcome' => 'failure');
    }

    return null;
}

/** Advance per-user failure cycles and return at most one recovery action each. */
function pmssLighttpdWatchdogNginxStateAdvance(
    array $state,
    array $events,
    array $usersByPort,
    int $threshold = PMSS_LIGHTTPD_WATCHDOG_NGINX_FAILURE_CYCLES
): array {
    $threshold = max(1, $threshold);
    $previousUsers = isset($state['users']) && is_array($state['users']) ? $state['users'] : array();
    $users = array();
    foreach ($usersByPort as $port => $username) {
        if (!pmssNetworkPortInRange((int) $port) || !is_string($username) || !pmssValidateUsername($username)) {
            continue;
        }
        $previous = isset($previousUsers[$username]) && is_array($previousUsers[$username])
            ? $previousUsers[$username]
            : array();
        $users[$username] = array(
            'failureCycles' => max(0, (int) ($previous['failureCycles'] ?? 0)),
            'recoveryStage' => min(2, max(0, (int) ($previous['recoveryStage'] ?? 0))),
        );
    }

    $pendingFailures = array();
    foreach ($events as $event) {
        $port = is_array($event) ? (int) ($event['port'] ?? 0) : 0;
        $username = $usersByPort[$port] ?? null;
        if (!is_string($username) || !isset($users[$username])) {
            continue;
        }
        if (($event['outcome'] ?? '') === 'healthy') {
            $users[$username] = array('failureCycles' => 0, 'recoveryStage' => 0);
            $pendingFailures[$username] = false;
        } elseif (($event['outcome'] ?? '') === 'failure') {
            $pendingFailures[$username] = true;
        }
    }

    $actions = array();
    foreach ($pendingFailures as $username => $failed) {
        if (!$failed) {
            continue;
        }
        if ($users[$username]['recoveryStage'] >= 2) {
            $users[$username]['failureCycles'] = 0;
            continue;
        }
        $users[$username]['failureCycles']++;
        if ($users[$username]['failureCycles'] < $threshold) {
            continue;
        }
        $users[$username]['failureCycles'] = 0;
        if ($users[$username]['recoveryStage'] === 0) {
            $actions[$username] = 'restart';
            $users[$username]['recoveryStage'] = 1;
        } elseif ($users[$username]['recoveryStage'] === 1) {
            $actions[$username] = 'reconfigure';
            $users[$username]['recoveryStage'] = 2;
        }
    }

    return array('users' => $users, 'actions' => $actions);
}
