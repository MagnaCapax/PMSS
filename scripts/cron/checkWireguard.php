#!/usr/bin/env php
<?php
/**
 * Ensure the WireGuard service remains healthy.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/wireguard.php';

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

/** Parse lsmod output without shell pipelines or regexing command strings. */
function pmssWireguardLsmodOutputHasModule(string $output): bool
{
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $columns = preg_split('/\s+/', trim($line));
        if (is_array($columns) && ($columns[0] ?? '') === 'wireguard') {
            return true;
        }
    }

    return false;
}

/** Return whether the WireGuard kernel module appears in lsmod output. */
function pmssWireguardKernelModuleLoaded(): bool
{
    $result = pmssWireguardCommandCapture('lsmod', []);
    if ($result['rc'] !== 0) {
        return false;
    }

    return pmssWireguardLsmodOutputHasModule($result['stdout']);
}

/**
 * Read the peer keys currently loaded in the kernel interface.
 *
 * @return array{status:string,keys:array<int,string>,rc:int}
 */
function pmssWireguardRunningPeerPublicKeys(): array
{
    $result = pmssWireguardCommandCapture('wg', ['show', 'wg0', 'peers']);
    if ($result['rc'] !== 0) {
        return ['status' => 'unreadable', 'keys' => [], 'rc' => $result['rc']];
    }

    $keys = [];
    $output = trim($result['stdout']) === '' ? [] : (preg_split('/\R/', trim($result['stdout'])) ?: []);
    foreach ($output as $line) {
        $key = trim($line);
        if (wgValidatePublicKey($key)) {
            $keys[$key] = true;
        }
    }
    $keys = array_keys($keys);
    sort($keys, SORT_STRING);

    return ['status' => 'ok', 'keys' => $keys, 'rc' => 0];
}

/** @return array<int,string> */
function pmssWireguardMissingPeerPublicKeys(array $configuredKeys, array $runningKeys): array
{
    $running = array_fill_keys($runningKeys, true);
    $missing = [];
    foreach ($configuredKeys as $key) {
        if (!isset($running[$key])) {
            $missing[] = $key;
        }
    }

    return $missing;
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

    $config = (string) $strip['stdout'];
    $written = @file_put_contents($tmp, $config);
    @chmod($tmp, 0600);
    if ($written === false || $written !== strlen($config)) {
        @unlink($tmp);
        return ['stage' => 'tempfile', 'rc' => 1];
    }

    $sync = pmssWireguardCommandCapture('wg', ['syncconf', 'wg0', $tmp]);
    @unlink($tmp);

    return ['stage' => 'syncconf', 'rc' => (int) $sync['rc']];
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
    $restartStatus = pmssWireguardCommandCapture('systemctl', ['restart', 'wg-quick@wg0'])['rc'];
    pmssWireguardReportCommandRc($peerUsers, $logPrefix, $restartStatus,
        'wg-quick@wg0 restarted successfully',
        'failed to restart wg-quick@wg0 (rc=%d)',
        'wireguard: wg-quick@wg0 restarted',
        'wireguard: wg-quick@wg0 restart failed (rc=%d)'
    );
}

/** Verify that configured peers are loaded into the running wg0 interface. */
function pmssWireguardReconcileConfiguredPeers(string $configPath, array $peerUsers, string $logPrefix, bool $debug): void
{
    $configured = pmssWireguardPeerPublicKeysFromConfig($configPath);
    if ($configured['status'] !== 'ok' || $configured['keys'] === []) {
        if ($debug) {
            echo $logPrefix . "wg0 config has no peers to reconcile\n";
        }
        return;
    }

    $running = pmssWireguardRunningPeerPublicKeys();
    if ($running['status'] !== 'ok') {
        echo $logPrefix . "failed to inspect running wg0 peers (rc={$running['rc']})\n";
        return;
    }

    $missing = pmssWireguardMissingPeerPublicKeys($configured['keys'], $running['keys']);
    if ($missing === []) {
        if ($debug) {
            echo $logPrefix . "wg0 runtime peers match config\n";
        }
        return;
    }

    echo $logPrefix . "wg0 missing ".count($missing)." configured peer(s), attempting syncconf\n";
    $sync = pmssWireguardSyncconfFromConfig();
    if ($sync['rc'] === 0) {
        echo $logPrefix . "wg0 peer set reconciled with syncconf\n";
        pmssWireguardLogUsers($peerUsers, 'wireguard: wg0 peer set reconciled with syncconf');
        return;
    }

    echo $logPrefix . "wg peer reconcile failed during {$sync['stage']} (rc={$sync['rc']}), attempting restart\n";
    pmssWireguardRestartWgQuick($peerUsers, $logPrefix);
}

/** Execute the WireGuard health check once. */
function pmssWireguardCheckMain(array $argv): int
{
    [$debug] = pmssCliArgvDebugSplit($argv);

    $logPrefix = date('c') . ' ';
    $configPath = pmssWireguardCheckConfigPath();
    $peerResult = pmssWireguardPeerUsersFromConfig($configPath);
    $peerStatus = (string) ($peerResult['status'] ?? 'unreadable');
    $peerUsers = is_array($peerResult['users'] ?? null) ? $peerResult['users'] : [];
    if ($peerStatus === 'missing') {
        if ($debug) {
            echo $logPrefix . "wireguard config missing; skipping check\n";
        }
        return 0;
    }
    if ($peerStatus !== 'ok') {
        echo $logPrefix . "wireguard config {$peerStatus}; skipping check\n";
        return 0;
    }

    if (!pmssWireguardKernelModuleLoaded()) {
        $rc = pmssWireguardCommandCapture('modprobe', ['wireguard'])['rc'];
        pmssWireguardReportCommandRc($peerUsers, $logPrefix, $rc,
            'loaded wireguard kernel module',
            'failed to load wireguard kernel module (rc=%d)',
            'wireguard: kernel module loaded',
            'wireguard: failed to load kernel module (rc=%d)'
        );
    }

    $wgQuickActive = pmssSystemdUnitIsActive('wg-quick@wg0');
    if ($wgQuickActive === true) {
        if ($debug) {
            echo $logPrefix . "wg-quick@wg0 active\n";
        }
        pmssWireguardReconcileConfiguredPeers($configPath, $peerUsers, $logPrefix, $debug);
        return 0;
    } elseif ($wgQuickActive === false) {
        echo $logPrefix . "wg-quick@wg0 inactive, attempting restart\n";
        pmssWireguardRestartWgQuick($peerUsers, $logPrefix);
        return 0;
    }

    $status = pmssWireguardCommandCapture('wg', ['show'])['rc'];
    if ($status !== 0) {
        echo $logPrefix . "wg0 interface missing; attempting wg-quick up\n";
        $rc = pmssWireguardCommandCapture('wg-quick', ['up', 'wg0'])['rc'];
        pmssWireguardReportCommandRc($peerUsers, $logPrefix, $rc,
            'wg0 brought up successfully',
            'failed to bring up wg0 (rc=%d)',
            'wireguard: wg0 brought up',
            'wireguard: wg0 bring-up failed (rc=%d)'
        );
    } elseif ($debug) {
        echo $logPrefix . "wg show reports interface active\n";
    }

    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssWireguardCheckMain');
