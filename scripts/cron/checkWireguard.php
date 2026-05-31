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
 * Read peer owner comments from wg0.conf without trusting path shape or content.
 *
 * @return array{status:string,users:array<int,string>}
 */
function pmssWireguardPeerUsersFromConfig(string $configPath): array
{
    if (!file_exists($configPath)) {
        return ['status' => 'missing', 'users' => []];
    }
    if (!is_file($configPath) || is_link($configPath)) {
        return ['status' => 'not_regular', 'users' => []];
    }

    $lines = @file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return ['status' => 'unreadable', 'users' => []];
    }

    $peerUsers = [];
    foreach ($lines as $line) {
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
        return 0;
    } elseif ($wgQuickActive === false) {
        echo $logPrefix . "wg-quick@wg0 inactive, attempting restart\n";
        exec('systemctl restart wg-quick@wg0', $out, $restartStatus);
        if ($restartStatus === 0) {
            echo $logPrefix . "wg-quick@wg0 restarted successfully\n";
            pmssWireguardLogUsers($peerUsers, 'wireguard: wg-quick@wg0 restarted');
        } else {
            echo $logPrefix . "failed to restart wg-quick@wg0 (rc={$restartStatus})\n";
            pmssWireguardLogUsers($peerUsers, sprintf('wireguard: wg-quick@wg0 restart failed (rc=%d)', $restartStatus));
        }
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
