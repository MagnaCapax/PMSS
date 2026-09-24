<?php
/**
 * Lighttpd watchdog php-cgi socket probe helpers.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/pathSafety.php';
require_once dirname(__DIR__).'/runtime.php';
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
    $probe = is_callable($options['probe'] ?? null) ? $options['probe'] : null;
    $sleep = is_callable($options['sleep'] ?? null) ? $options['sleep'] : 'sleep';

    if ($socketPath === '') {
        return array('ok' => false, 'errno' => 0, 'errstr' => 'socket path missing', 'attempts' => 1);
    }
    // Reject embedded terminators before a socket API or injected probe sees the path.
    if (strpos($socketPath, "\0") !== false) {
        return array('ok' => false, 'errno' => 0, 'errstr' => 'socket path invalid', 'attempts' => 1);
    }

    for ($attempt = 1; $attempt <= $attemptCount; $attempt++) {
        if ($probe !== null) {
            $probeResult = $probe($socketPath, $timeoutSeconds);
            $result = is_array($probeResult)
                ? array(
                    'ok' => !empty($probeResult['ok']),
                    'errno' => (int) ($probeResult['errno'] ?? 0),
                    'errstr' => (string) ($probeResult['errstr'] ?? ''),
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
function pmssLighttpdWatchdogSocketPathsFromLines(array $lines, string $homeDir): array
{
    $homeDir = rtrim($homeDir, '/');
    if ($homeDir === '' || !pmssPathAbsoluteStringIsSafe($homeDir)) {
        return array();
    }

    $baseSocketPath = $homeDir.'/.lighttpd/php.socket';
    $pathPattern = '~^'.preg_quote($baseSocketPath, '~').'(?:-[0-9]+)?$~D';
    $listeningPaths = array();
    foreach ($lines as $line) {
        if (($columns = pmssConfigLineColumns((string) $line, 5, [])) === []
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

/** Read one bounded live-listener snapshot without trusting socket files on disk. */
function pmssLighttpdWatchdogListeningSocketSnapshot(string $homeDir, array $options = array()): array
{
    $reader = is_callable($options['reader'] ?? null) ? $options['reader'] : null;
    if ($reader === null) {
        $timeoutSeconds = max(1, (int) ($options['timeoutSeconds'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS));
        $reader = static function () use ($timeoutSeconds): array {
            $result = pmssCommandCapture(pmssBuildCommand('ss', array('-xln')), $timeoutSeconds);
            $stdout = trim((string) ($result['stdout'] ?? ''));

            return array(
                'lines' => $stdout === '' ? array() : preg_split('/\r?\n/', $stdout),
                'rc' => (int) ($result['rc'] ?? 1),
            );
        };
    }
    $result = $reader();
    if (!is_array($result)
        || (int) ($result['rc'] ?? 1) !== 0
        || !is_array($result['lines'] ?? null)
    ) {
        return array('ok' => false, 'paths' => array());
    }

    return array('ok' => true, 'paths' => pmssLighttpdWatchdogSocketPathsFromLines($result['lines'], $homeDir));
}

/** Return true when the account has at least its configured listener count. */
function pmssLighttpdWatchdogListenerCoverageIsHealthy(array $expectedPaths, array $listeningPaths): bool
{
    $expectedCount = count(array_unique($expectedPaths));

    return $expectedCount > 0 && count(array_unique($listeningPaths)) >= $expectedCount;
}

/** Verify a restart restored listener coverage, allowing a brief bounded startup window. */
function pmssLighttpdWatchdogRestartVerify(string $homeDir, array $expectedPaths, array $options = array()): array
{
    $attemptCount = max(1, (int) ($options['attemptCount'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS));
    $retryDelaySeconds = max(0, (int) ($options['retryDelaySeconds'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS));
    $sleep = is_callable($options['sleep'] ?? null) ? $options['sleep'] : 'sleep';
    unset($options['attemptCount'], $options['retryDelaySeconds'], $options['sleep']);

    for ($attempt = 1; $attempt <= $attemptCount; $attempt++) {
        $snapshot = pmssLighttpdWatchdogListeningSocketSnapshot($homeDir, $options);
        $healthy = $snapshot['ok'] && pmssLighttpdWatchdogListenerCoverageIsHealthy($expectedPaths, $snapshot['paths']);
        if ($healthy) {
            break;
        }
        if ($attempt < $attemptCount && $retryDelaySeconds > 0) {
            $sleep($retryDelaySeconds);
        }
    }

    return array(
        'status' => $healthy ? 'healthy' : ($snapshot['ok'] ? 'restart_attempted_still_down' : 'restart_attempted_unverified'),
        'attempts' => min($attempt, $attemptCount),
        'expected' => count(array_unique($expectedPaths)),
        'observed' => count($snapshot['paths']), // The snapshot parser already keys paths uniquely.
    );
}

/** Identify refused stale-index probes only when all configured worker slots remain represented. */
function pmssLighttpdWatchdogSocketFailureIsStaleIndex(
    int $errno,
    array $expectedPaths,
    array $listeningPaths
): bool
{
    return $errno === PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED
        && pmssLighttpdWatchdogListenerCoverageIsHealthy($expectedPaths, $listeningPaths);
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

    $statePath = $runtimeDir.'/checkLighttpdInstances-socket-'.$username.'.count';
    // Validate the marker itself before either recording or clearing failures.
    return pmssPathTargetIsSafe($statePath, false) ? $statePath : '';
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
    $encodedCount = (string) $count;
    // A partial write must not authorize a destructive restart.
    if (@file_put_contents($statePath, $encodedCount, LOCK_EX) !== strlen($encodedCount)) {
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
    if (pmssRegularFilePathIsReadable($statePath)) {
        @unlink($statePath);
    }
}
