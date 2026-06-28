<?php
/**
 * Lighttpd watchdog php-cgi socket probe helpers.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/pathSafety.php';
require_once dirname(__DIR__).'/user/identity.php';

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS', 4);
}

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_FAILURE_CYCLES')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_FAILURE_CYCLES', 3);
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
    $attemptCount = max(1, (int) ($options['attemptCount'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS));
    $retryDelaySeconds = max(0, (int) ($options['retryDelaySeconds'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS));
    $timeoutSeconds = max(1, (int) ($options['timeoutSeconds'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS));
    $probe = isset($options['probe']) && is_callable($options['probe']) ? $options['probe'] : null;
    $sleep = isset($options['sleep']) && is_callable($options['sleep']) ? $options['sleep'] : 'sleep';

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

/** Return the per-user marker path for consecutive php-cgi socket failures. */
function pmssLighttpdWatchdogSocketFailureStatePath(string $username, string $runtimeDir = ''): string
{
    if (!pmssValidateUsername($username)) {
        return '';
    }

    $runtimeDir = $runtimeDir === '' ? pmssRuntimeDir() : rtrim($runtimeDir, '/');
    if ($runtimeDir === ''
        || !pmssPathAbsoluteStringIsSafe($runtimeDir)
        || !pmssPathTargetIsSafe($runtimeDir, true)
    ) {
        return '';
    }

    return $runtimeDir.'/checkLighttpdInstances-socket-'.$username.'.count';
}

/**
 * Record a failed socket probe and say whether the destructive restart gate is met.
 *
 * @return array{action:string,count:int,threshold:int}
 */
function pmssLighttpdWatchdogRecordSocketFailure(string $username, array $options = array()): array
{
    $threshold = max(1, (int) ($options['threshold'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_FAILURE_CYCLES));
    $runtimeDir = (string) ($options['runtimeDir'] ?? '');
    $statePath = pmssLighttpdWatchdogSocketFailureStatePath($username, $runtimeDir);

    if ($statePath === '' || !pmssDirEnsureExists(dirname($statePath), 0755)) {
        return array('action' => 'wait', 'count' => 0, 'threshold' => $threshold);
    }

    $count = max(0, pmssReadRegularFileInt($statePath)) + 1;
    if (@file_put_contents($statePath, (string) $count, LOCK_EX) === false) {
        return array('action' => 'wait', 'count' => 0, 'threshold' => $threshold);
    }

    return array(
        'action' => $count >= $threshold ? 'restart' : 'wait',
        'count' => $count,
        'threshold' => $threshold,
    );
}

/** Clear resolved php-cgi socket failure state for a user. */
function pmssLighttpdWatchdogClearSocketFailure(string $username, array $options = array()): void
{
    $runtimeDir = (string) ($options['runtimeDir'] ?? '');
    $statePath = pmssLighttpdWatchdogSocketFailureStatePath($username, $runtimeDir);
    if ($statePath !== '' && is_file($statePath) && !is_link($statePath)) {
        @unlink($statePath);
    }
}
