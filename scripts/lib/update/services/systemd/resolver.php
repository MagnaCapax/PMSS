<?php
/** Failed resolver observation and package recovery. @license GPL-3.0-only */
/**
 * Pure: whether `systemctl show unbound` output describes a failing resolver.
 *
 * "failed" counts, and so does a unit cycling through Restart=on-failure: it is
 * "activating" or "inactive" between attempts, never "failed", while NRestarts
 * climbs and Result is not "success" (Debian 10's unbound with a stale root trust
 * anchor loops like this and takes DNS down with it).
 */
function pmssUnboundShowIndicatesFailure(string $show): bool
{
    $props = [];
    foreach (explode("\n", $show) as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $props[$parts[0]] = trim($parts[1]);
    }
    $state = $props['ActiveState'] ?? '';
    return $state === 'failed' || (in_array($state, ['activating', 'inactive'], true)
        && (int) ($props['NRestarts'] ?? '0') > 0
        && ($props['Result'] ?? 'success') !== 'success');
}

/**
 * Purge the unbound DNS resolver if its service is failing.
 *
 * Debian 11/12 servers may have unbound installed but failing because
 * external nameservers are configured instead, and a
 * Debian 10 install from the old baseline loops on a stale root trust anchor.
 * Purging the failing package removes its resolver registration.
 *
 * Only removes a unit that is failed or restart-looping - not one that is
 * active, cleanly stopped, or not installed.
 */
function pmssPurgeFailedUnbound(): void
{
    // Skip if systemd is not available (containers, very old systems)
    if (pmssSystemdActionSkip(pmssSystemdActionSkipReason(), 'Checking unbound service status')) {
        return;
    }
    $show = (string) @shell_exec('systemctl show unbound -p ActiveState -p NRestarts -p Result 2>/dev/null');
    if (!pmssUnboundShowIndicatesFailure($show)) {
        return;
    }

    runStep('Purging failed unbound service', aptCmd('purge -y unbound'));
}
