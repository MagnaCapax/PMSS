<?php
/**
 * Probe and recover the host systemd/logind control channel from root cron.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/update/runtime/commands.php';

/** Return an empty reason only when both control-channel calls work. */
function pmssSystemdControlChannelFailure(callable $capture): string
{
    $version = $capture('systemctl show --property=Version', 15);
    if (($version['rc'] ?? 1) !== 0 || preg_match('/^Version=\S+/m', (string) ($version['stdout'] ?? '')) !== 1) {
        return 'systemctl_version';
    }

    $sessions = $capture('loginctl list-sessions', 15);
    return ($sessions['rc'] ?? 1) === 0 ? '' : 'loginctl_sessions';
}

/** Repair in dependency order, then verify through the same functional probes. */
function pmssSystemdControlChannelCheck(callable $capture, callable $step, callable $log): int
{
    $failure = pmssSystemdControlChannelFailure($capture);
    if ($failure === '') {
        return 0;
    }

    $log('control channel failed: '.$failure.'; starting recovery');
    foreach ([
        ['Reexecuting systemd control channel', 'systemctl daemon-reexec'],
        ['Restarting D-Bus control channel', 'systemctl restart dbus'],
        ['Restarting logind control channel', 'systemctl restart systemd-logind'],
    ] as $action) {
        // Keep a wedged manager from holding this root cron process indefinitely.
        if ($step($action[0], 'timeout --kill-after=5s 45s '.$action[1]) !== 0) {
            $log('control channel recovery failed: '.$action[1]);
            return 1;
        }
    }

    $failure = pmssSystemdControlChannelFailure($capture);
    $log($failure === '' ? 'control channel recovered' : 'control channel still failed: '.$failure);
    return $failure === '' ? 0 : 1;
}
