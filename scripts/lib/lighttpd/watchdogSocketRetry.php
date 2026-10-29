<?php
/** Shared socket-health retry cadence; probes retain their own result policy. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime.php';

// Preserve operator-defined constants and the defaults consumed by existing callers.
foreach (array(
    'PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS' => 4,
    'PMSS_LIGHTTPD_WATCHDOG_SOCKET_FAILURE_CYCLES' => 3,
    'PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS' => 2,
    'PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_TIMEOUT_SECONDS' => 5,
    'PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED' => 111,
) as $pmssSocketConstant => $pmssSocketDefault) {
    if (!defined($pmssSocketConstant)) define($pmssSocketConstant, $pmssSocketDefault);
}
unset($pmssSocketConstant, $pmssSocketDefault);

/** Return the first successful observation or the last failure, with its actual attempt count. */
function pmssLighttpdWatchdogSocketRetry(callable $probe, array $options): array
{
    $attemptCount = max(1, (int) ($options['attemptCount'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS));
    $retryDelaySeconds = max(0, (int) ($options['retryDelaySeconds'] ?? PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS));
    $sleep = is_callable($options['sleep'] ?? null) ? $options['sleep'] : 'sleep';
    for ($attempt = 1; $attempt <= $attemptCount; $attempt++) {
        $result = $probe();
        $result['attempts'] = $attempt;
        if ($result['ok']) return $result;
        // Busy workers can briefly refuse connects. Never delay after success or the final failure.
        if ($attempt < $attemptCount && $retryDelaySeconds > 0) $sleep($retryDelaySeconds);
    }
    return $result;
}
