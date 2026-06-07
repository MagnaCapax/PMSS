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

/** Return the wg0 config path, allowing hermetic tests to inject a fixture. */
function pmssWireguardCheckConfigPath(): string
{
    if (function_exists('pmssTestModeEnabled') && pmssTestModeEnabled()) {
        $override = getenv('PMSS_WIREGUARD_CONFIG_PATH');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }
    }

    return '/etc/wireguard/wg0.conf';
}

/** Write one status line to every valid peer user discovered from wg0.conf. */
function pmssWireguardLogUsers(array $users, string $message): void
{
    foreach ($users as $user) {
        pmssUserLog($user, $message);
    }
}

/**
 * Read wg0.conf lines without trusting path shape or content.
 *
 * @return array{status:string,lines:array<int,string>}
 */
function pmssWireguardConfigLines(string $configPath): array
{
    if (!file_exists($configPath)) {
        return ['status' => 'missing', 'lines' => []];
    }
    if (!is_file($configPath) || is_link($configPath)) {
        return ['status' => 'not_regular', 'lines' => []];
    }

    $lines = @file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return ['status' => 'unreadable', 'lines' => []];
    }

    return ['status' => 'ok', 'lines' => $lines];
}

/**
 * Read peer owner comments from wg0.conf without trusting path shape or content.
 *
 * @return array{status:string,users:array<int,string>}
 */
function pmssWireguardPeerUsersFromConfig(string $configPath): array
{
    $config = pmssWireguardConfigLines($configPath);
    if ($config['status'] !== 'ok') {
        return ['status' => $config['status'], 'users' => []];
    }

    $peerUsers = [];
    foreach ($config['lines'] as $line) {
        $line = trim($line);
        if (preg_match('/^#\s*user\s*=\s*([A-Za-z0-9._-]+)\s*$/', $line, $matches)) {
            $user = $matches[1];
            if (!pmssValidateUsername($user)) {
                continue;
            }
            $peerUsers[$user] = true;
        }
    }
    $peerUsers = array_keys($peerUsers);
    sort($peerUsers, SORT_NATURAL | SORT_FLAG_CASE);

    return ['status' => 'ok', 'users' => $peerUsers];
}

/** Validate a WireGuard public key before comparing configured/runtime state. */
function pmssWireguardPublicKeyIsValid(string $key): bool
{
    $key = trim($key);
    if ($key === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $key) !== 1) {
        return false;
    }

    $decoded = base64_decode($key, true);
    return $decoded !== false && strlen($decoded) === 32;
}

/**
 * Read configured peer public keys from wg0.conf.
 *
 * @return array{status:string,keys:array<int,string>}
 */
function pmssWireguardPeerPublicKeysFromConfig(string $configPath): array
{
    $config = pmssWireguardConfigLines($configPath);
    if ($config['status'] !== 'ok') {
        return ['status' => $config['status'], 'keys' => []];
    }

    $keys = [];
    foreach ($config['lines'] as $line) {
        if (preg_match('/^PublicKey\s*=\s*([A-Za-z0-9+\/=]+)\s*$/', trim($line), $matches) !== 1) {
            continue;
        }
        if (pmssWireguardPublicKeyIsValid($matches[1])) {
            $keys[$matches[1]] = true;
        }
    }

    $keys = array_keys($keys);
    sort($keys, SORT_STRING);

    return ['status' => 'ok', 'keys' => $keys];
}

/**
 * Read the peer keys currently loaded in the kernel interface.
 *
 * @return array{status:string,keys:array<int,string>,rc:int}
 */
function pmssWireguardRunningPeerPublicKeys(): array
{
    $output = [];
    exec(pmssBuildCommand('wg', ['show', 'wg0', 'peers']), $output, $rc);
    if ($rc !== 0) {
        return ['status' => 'unreadable', 'keys' => [], 'rc' => $rc];
    }

    $keys = [];
    foreach ($output as $line) {
        $key = trim($line);
        if (pmssWireguardPublicKeyIsValid($key)) {
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
    $strip = pmssCommandCapture(pmssBuildCommand('wg-quick', ['strip', 'wg0']), 30);
    if ((int) $strip['rc'] !== 0 || trim((string) $strip['stdout']) === '') {
        return ['stage' => 'strip', 'rc' => (int) $strip['rc']];
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'pmss-wg-sync-');
    if ($tmp === false) {
        return ['stage' => 'tempfile', 'rc' => 1];
    }

    $written = @file_put_contents($tmp, (string) $strip['stdout']);
    @chmod($tmp, 0600);
    if ($written === false) {
        @unlink($tmp);
        return ['stage' => 'tempfile', 'rc' => 1];
    }

    $sync = pmssCommandCapture(pmssBuildCommand('wg', ['syncconf', 'wg0', $tmp]), 30);
    @unlink($tmp);

    return ['stage' => 'syncconf', 'rc' => (int) $sync['rc']];
}

/** Restart wg-quick@wg0 and report the outcome consistently. */
function pmssWireguardRestartWgQuick(array $peerUsers, string $logPrefix): void
{
    exec(pmssBuildCommand('systemctl', ['restart', 'wg-quick@wg0']), $out, $restartStatus);
    if ($restartStatus === 0) {
        echo $logPrefix . "wg-quick@wg0 restarted successfully\n";
        pmssWireguardLogUsers($peerUsers, 'wireguard: wg-quick@wg0 restarted');
    } else {
        echo $logPrefix . "failed to restart wg-quick@wg0 (rc={$restartStatus})\n";
        pmssWireguardLogUsers($peerUsers, sprintf('wireguard: wg-quick@wg0 restart failed (rc=%d)', $restartStatus));
    }
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

    exec('lsmod | grep -q "^wireguard\b"', $null, $moduleStatus);
    if ($moduleStatus !== 0) {
        exec('modprobe wireguard', $out, $rc);
        if ($rc !== 0) {
            echo $logPrefix . "failed to load wireguard kernel module (rc={$rc})\n";
            pmssWireguardLogUsers($peerUsers, sprintf('wireguard: failed to load kernel module (rc=%d)', $rc));
        } else {
            echo $logPrefix . "loaded wireguard kernel module\n";
            pmssWireguardLogUsers($peerUsers, 'wireguard: kernel module loaded');
        }
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

    exec('wg show', $out, $status);
    if ($status !== 0) {
        echo $logPrefix . "wg0 interface missing; attempting wg-quick up\n";
        exec('wg-quick up wg0', $out, $rc);
        if ($rc === 0) {
            echo $logPrefix . "wg0 brought up successfully\n";
            pmssWireguardLogUsers($peerUsers, 'wireguard: wg0 brought up');
        } else {
            echo $logPrefix . "failed to bring up wg0 (rc={$rc})\n";
            pmssWireguardLogUsers($peerUsers, sprintf('wireguard: wg0 bring-up failed (rc=%d)', $rc));
        }
    } elseif ($debug) {
        echo $logPrefix . "wg show reports interface active\n";
    }

    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssWireguardCheckMain');
