<?php
/**
 * Lighttpd watchdog socket-health facade and connection transport.
 *
 * Existing callers load this path for probes, listener verification and failure state.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/watchdogSocketListeners.php';
require_once __DIR__.'/watchdogSocketState.php';
require_once __DIR__.'/watchdogSocketHealth.php';

/** Retry a php-cgi Unix socket probe before treating the worker pool as dead. */
function pmssLighttpdWatchdogSocketProbeWithRetry(string $socketPath, array $options = array()): array
{
    $timeoutSeconds = max(1, (int) ($options['timeoutSeconds'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS));
    $probe = is_callable($options['probe'] ?? null) ? $options['probe'] : 'pmssLighttpdWatchdogSocketConnect';
    // Reject embedded terminators before a socket API or injected probe sees the path.
    if ($socketPath === '' || strpos($socketPath, "\0") !== false) {
        return array('ok' => false, 'errno' => 0, 'errstr' => $socketPath === '' ? 'socket path missing' : 'socket path invalid', 'attempts' => 1);
    }

    return pmssLighttpdWatchdogSocketRetry(static function () use ($probe, $socketPath, $timeoutSeconds): array {
        $result = $probe($socketPath, $timeoutSeconds);
        return is_array($result)
            ? array('ok' => !empty($result['ok']), 'errno' => (int) ($result['errno'] ?? 0), 'errstr' => (string) ($result['errstr'] ?? ''))
            : array('ok' => false, 'errno' => 0, 'errstr' => 'probe callback returned invalid result');
    }, $options);
}

/** Observe and close one native connection; errno and errstr also participate in success. */
function pmssLighttpdWatchdogSocketConnect(string $socketPath, int $timeoutSeconds): array
{
    $errno = 0;
    $errstr = '';
    $socket = fsockopen('unix://'.$socketPath, 0, $errno, $errstr, $timeoutSeconds);
    $result = array('ok' => $socket !== false && $errno === 0 && $errstr === '', 'errno' => (int) $errno, 'errstr' => (string) $errstr);
    if (is_resource($socket)) fclose($socket);
    return $result;
}
