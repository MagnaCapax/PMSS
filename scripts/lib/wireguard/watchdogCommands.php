<?php
/** WireGuard watchdog bounded commands, config staging and outcome reporting.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Write one status line to every valid peer user discovered from wg0.conf. */
function pmssWireguardLogUsers(array $users, string $message): void
{
    foreach ($users as $user) {
        pmssUserLog($user, $message);
    }
}

/**
 * Run one WireGuard maintenance command through a bounded capture boundary.
 *
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssWireguardCommandCapture(string $program, array $args, int $timeoutSec = 30): array
{
    $result = pmssCommandCapture(pmssBuildCommand($program, $args), $timeoutSec);

    return [
        'rc' => (int) ($result['rc'] ?? 1),
        'stdout' => (string) ($result['stdout'] ?? ''),
        'stderr' => (string) ($result['stderr'] ?? ''),
    ];
}

/**
 * Reconcile wg0 with wg0.conf without flapping the interface when possible.
 *
 * @return array{stage:string,rc:int}
 */
function pmssWireguardSyncconfFromConfig(): array
{
    $strip = pmssWireguardCommandCapture('wg-quick', ['strip', 'wg0']);
    if ((int) $strip['rc'] !== 0 || trim((string) $strip['stdout']) === '') {
        return ['stage' => 'strip', 'rc' => (int) $strip['rc']];
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'pmss-wg-sync-');
    if ($tmp === false) {
        return ['stage' => 'tempfile', 'rc' => 1];
    }

    try {
        $config = (string) $strip['stdout'];
        $written = @file_put_contents($tmp, $config);
        $secured = @chmod($tmp, 0600);
        if ($written === false || $written !== strlen($config) || !$secured) {
            return ['stage' => 'tempfile', 'rc' => 1];
        }

        $sync = pmssWireguardCommandCapture('wg', ['syncconf', 'wg0', $tmp]);

        return ['stage' => 'syncconf', 'rc' => (int) $sync['rc']];
    } finally {
        // Stripped configuration may contain private keys; thrown faults still require cleanup.
        @unlink($tmp);
    }
}

/** Emit the standard command rc status to stdout and per-user logs. */
function pmssWireguardReportCommandRc(
    array $peerUsers, string $logPrefix, int $rc, string $okLine,
    string $failLine, string $okUserLog, string $failUserLog
): void {
    $ok = $rc === 0;
    echo $logPrefix . ($ok ? $okLine : sprintf($failLine, $rc)) . "\n";
    pmssWireguardLogUsers($peerUsers, $ok ? $okUserLog : sprintf($failUserLog, $rc));
}

/** Restart wg-quick@wg0 and report the outcome consistently. */
function pmssWireguardRestartWgQuick(array $peerUsers, string $logPrefix): void
{
    pmssWireguardReportCommandRc($peerUsers, $logPrefix,
        pmssWireguardCommandCapture('systemctl', ['restart', 'wg-quick@wg0'])['rc'],
        'wg-quick@wg0 restarted successfully',
        'failed to restart wg-quick@wg0 (rc=%d)',
        'wireguard: wg-quick@wg0 restarted',
        'wireguard: wg-quick@wg0 restart failed (rc=%d)'
    );
}
