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

if (!defined('PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED')) {
    define('PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED', 111);
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

/**
 * Parse strict `ss -xln` LISTEN rows for one account's php-cgi sockets.
 *
 * @param string[] $lines
 * @return string[]
 */
function pmssLighttpdWatchdogListeningSocketPathsFromLines(array $lines, string $homeDir): array
{
    $homeDir = rtrim($homeDir, '/');
    if ($homeDir === '' || !pmssPathAbsoluteStringIsSafe($homeDir)) {
        return array();
    }

    $baseSocketPath = $homeDir.'/.lighttpd/php.socket';
    $pathPattern = '~^'.preg_quote($baseSocketPath, '~').'(?:-[0-9]+)?$~D';
    $listeningPaths = array();
    foreach ($lines as $line) {
        $columns = preg_split('/\s+/', trim((string) $line));
        if (!is_array($columns)
            || count($columns) < 5
            || ($columns[1] ?? '') !== 'LISTEN'
            || !ctype_digit((string) ($columns[2] ?? ''))
            || !ctype_digit((string) ($columns[3] ?? ''))
        ) {
            continue;
        }
        foreach ($columns as $column) {
            if (preg_match($pathPattern, (string) $column) === 1) {
                $listeningPaths[(string) $column] = (string) $column;
            }
        }
    }

    return array_values($listeningPaths);
}

/** Read live php-cgi listener paths without trusting socket files on disk. */
function pmssLighttpdWatchdogListeningSocketPaths(string $homeDir, array $options = array()): array
{
    $reader = isset($options['reader']) && is_callable($options['reader']) ? $options['reader'] : null;
    if ($reader === null) {
        $reader = static function (): array {
            $lines = array();
            $rc = 1;
            @exec('ss -xln 2>/dev/null', $lines, $rc);

            return array('lines' => $lines, 'rc' => $rc);
        };
    }
    $result = $reader();
    if (!is_array($result)
        || (int) ($result['rc'] ?? 1) !== 0
        || !isset($result['lines'])
        || !is_array($result['lines'])
    ) {
        return array();
    }

    return pmssLighttpdWatchdogListeningSocketPathsFromLines($result['lines'], $homeDir);
}

/** Identify refused stale-index probes only when all configured worker slots remain represented. */
function pmssLighttpdWatchdogSocketFailureIsStaleIndex(
    int $errno,
    array $expectedPaths,
    array $listeningPaths
): bool
{
    $expectedCount = count(array_unique($expectedPaths));

    return $errno === PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED
        && $expectedCount > 0
        && count(array_unique($listeningPaths)) >= $expectedCount;
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
