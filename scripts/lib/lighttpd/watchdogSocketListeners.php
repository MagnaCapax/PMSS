<?php
/** Live php-cgi listener snapshots and restart verification. @license GPL-3.0-only */

require_once dirname(__DIR__).'/pathSafety.php';
require_once __DIR__.'/watchdogSocketRetry.php';

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
    foreach (pmssConfigRows($lines, 5, []) as $columns) {
        if (($columns[1] ?? '') !== 'LISTEN'
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
                'lines' => $stdout === '' ? array() : pmssTextLines($stdout),
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
    $snapshot = pmssLighttpdWatchdogSocketRetry(static function () use ($homeDir, $expectedPaths, $options): array {
        $snapshot = pmssLighttpdWatchdogListeningSocketSnapshot($homeDir, $options);
        return array('ok' => $snapshot['ok'] && pmssLighttpdWatchdogListenerCoverageIsHealthy($expectedPaths, $snapshot['paths']),
            'readable' => $snapshot['ok'], 'paths' => $snapshot['paths']);
    }, $options);

    return array(
        'status' => $snapshot['ok'] ? 'healthy' : ($snapshot['readable'] ? 'restart_attempted_still_down' : 'restart_attempted_unverified'),
        'attempts' => $snapshot['attempts'],
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
