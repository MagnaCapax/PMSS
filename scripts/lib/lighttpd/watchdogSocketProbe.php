<?php
/**
 * Lighttpd watchdog php-cgi socket probe helpers.
 *
 * @license GPL-3.0-only
 */

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS', 4);
}

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS', 2);
}

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS', 5);
}

/** Retry a php-cgi Unix socket probe before treating the worker pool as dead. */
function pmssLighttpdWatchdogSocketProbeWithRetry(string $socketPath, array $options = array()): array
{
    $attemptCount = isset($options['attemptCount']) ? (int) $options['attemptCount'] : PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS;
    $retryDelaySeconds = isset($options['retryDelaySeconds']) ? (int) $options['retryDelaySeconds'] : PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS;
    $timeoutSeconds = isset($options['timeoutSeconds']) ? (int) $options['timeoutSeconds'] : PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS;
    $probe = isset($options['probe']) && is_callable($options['probe']) ? $options['probe'] : null;
    $sleep = isset($options['sleep']) && is_callable($options['sleep']) ? $options['sleep'] : 'sleep';

    $attemptCount = max(1, $attemptCount);
    $retryDelaySeconds = max(0, $retryDelaySeconds);
    $timeoutSeconds = max(1, $timeoutSeconds);
    if ($socketPath === '') {
        return array('ok' => false, 'errno' => 0, 'errstr' => 'socket path missing', 'attempts' => 1);
    }

    $result = array('ok' => false, 'errno' => 0, 'errstr' => '', 'attempts' => 0);
    for ($attempt = 1; $attempt <= $attemptCount; $attempt++) {
        if ($probe !== null) {
            $probeResult = $probe($socketPath, $timeoutSeconds);
            $result = is_array($probeResult)
                ? array(
                    'ok' => !empty($probeResult['ok']),
                    'errno' => isset($probeResult['errno']) ? (int) $probeResult['errno'] : 0,
                    'errstr' => isset($probeResult['errstr']) ? (string) $probeResult['errstr'] : '',
                )
                : array('ok' => false, 'errno' => 0, 'errstr' => 'probe callback returned invalid result');
        } else {
            $errno = 0;
            $errstr = '';
            $socket = fsockopen('unix://'.$socketPath, 0, $errno, $errstr, $timeoutSeconds);
            $result = array(
                'ok' => $socket !== false && $errno === 0 && $errstr === '',
                'errno' => (int) $errno,
                'errstr' => (string) $errstr,
            );
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $result['attempts'] = $attempt;
        if ($result['ok']) {
            return $result;
        }

        // Busy php-cgi workers can briefly refuse new socket connects.
        if ($attempt < $attemptCount && $retryDelaySeconds > 0) {
            $sleep($retryDelaySeconds);
        }
    }

    return $result;
}
