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

$debug = pmssCliArgvHasToken($argv ?? null, '--debug');

$logPrefix = date('c') . ' ';
if (!file_exists('/etc/wireguard/wg0.conf')) {
    if ($debug) {
        echo $logPrefix . "wireguard config missing; skipping check\n";
    }
    exit(0);
}

function pmssWireguardLogUsers(array $users, string $message): void
{
    foreach ($users as $user) {
        pmssUserLog($user, $message);
    }
}

$peerUsers = [];
$lines = @file('/etc/wireguard/wg0.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (is_array($lines)) {
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
    exit(0);
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
} else {
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
}
