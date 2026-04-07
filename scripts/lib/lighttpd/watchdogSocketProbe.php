<?php
/**
 * Lighttpd watchdog php-cgi socket probe helpers.
 *
 * @license GPL-3.0-only
 */

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS', 2);
}

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS', 1);
}

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS', 5);
}

/** Connect once to a php-cgi Unix socket and report the outcome. */
function pmssLighttpdWatchdogSocketProbeOnce(string $socketPath, int $timeoutSeconds = PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS, $probe = null): array
{
    if ($socketPath === '') {
        return array('ok' => false, 'errno' => 0, 'errstr' => 'socket path missing');
    }

    if ($probe !== null) {
        $result = $probe($socketPath, $timeoutSeconds);
        if (!is_array($result)) {
            return array('ok' => false, 'errno' => 0, 'errstr' => 'probe callback returned invalid result');
        }

        return array(
            'ok' => !empty($result['ok']),
            'errno' => isset($result['errno']) ? (int) $result['errno'] : 0,
            'errstr' => isset($result['errstr']) ? (string) $result['errstr'] : '',
        );
    }

    $errno = 0;
    $errstr = '';
    $socket = fsockopen('unix://'.$socketPath, 0, $errno, $errstr, $timeoutSeconds);
    $ok = $socket !== false && $errno === 0 && $errstr === '';
    if (is_resource($socket)) {
        fclose($socket);
    }

    return array('ok' => $ok, 'errno' => (int) $errno, 'errstr' => (string) $errstr);
}

/** Retry a php-cgi Unix socket probe before treating the worker pool as dead. */
function pmssLighttpdWatchdogSocketProbeWithRetry(string $socketPath, array $options = array()): array
{
    $attemptCount = isset($options['attemptCount']) ? (int) $options['attemptCount'] : PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS;
    $retryDelaySeconds = isset($options['retryDelaySeconds']) ? (int) $options['retryDelaySeconds'] : PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS;
    $timeoutSeconds = isset($options['timeoutSeconds']) ? (int) $options['timeoutSeconds'] : PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS;
    $probe = isset($options['probe']) && is_callable($options['probe']) ? $options['probe'] : null;
    $sleep = isset($options['sleep']) && is_callable($options['sleep']) ? $options['sleep'] : 'sleep';

    if ($attemptCount < 1) {
        $attemptCount = 1;
    }
    if ($retryDelaySeconds < 0) {
        $retryDelaySeconds = 0;
    }
    if ($timeoutSeconds < 1) {
        $timeoutSeconds = 1;
    }
    if ($socketPath === '') {
        return array('ok' => false, 'errno' => 0, 'errstr' => 'socket path missing', 'attempts' => 1);
    }

    $result = array('ok' => false, 'errno' => 0, 'errstr' => '', 'attempts' => 0);
    for ($attempt = 1; $attempt <= $attemptCount; $attempt++) {
        $result = pmssLighttpdWatchdogSocketProbeOnce($socketPath, $timeoutSeconds, $probe);
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
