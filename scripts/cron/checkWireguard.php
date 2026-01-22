#!/usr/bin/env php
<?php
/**
 * Ensure the WireGuard service remains healthy.
 */
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
$pmssUserLifecyclePath = __DIR__.'/../lib/userLifecycle.php';
if (is_file($pmssUserLifecyclePath)) {
    require_once $pmssUserLifecyclePath;
}

$args = isset($argv) ? $argv : (isset($_SERVER['argv']) ? $_SERVER['argv'] : []);
$debug = in_array('--debug', $args, true);

$logPrefix = date('c') . ' ';
$config = '/etc/wireguard/wg0.conf';
if (!file_exists($config)) {
    if ($debug) {
        echo $logPrefix . "wireguard config missing; skipping check\n";
    }
    exit(0);
}

function pmssWireguardPeerUsers(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $users = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^#\s*user\s*=\s*([A-Za-z0-9._-]+)\s*$/', $line, $matches)) {
            $user = $matches[1];
            if (function_exists('pmssValidateUsername') && !pmssValidateUsername($user)) {
                continue;
            }
            $users[$user] = true;
        }
    }
    $userList = array_keys($users);
    sort($userList, SORT_NATURAL | SORT_FLAG_CASE);
    return $userList;
}

function pmssWireguardLogUsers(array $users, string $message): void
{
    if (empty($users) || !function_exists('pmssUserLog')) {
        return;
    }
    foreach ($users as $user) {
        pmssUserLog($user, $message);
    }
}

$peerUsers = pmssWireguardPeerUsers($config);

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

if (is_dir('/run/systemd/system')) {
    exec('systemctl is-active --quiet wg-quick@wg0', $out, $status);
    if ($status === 0) {
        if ($debug) {
            echo $logPrefix . "wg-quick@wg0 active\n";
        }
        exit(0);
    }
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
    } else {
        if ($debug) {
            echo $logPrefix . "wg show reports interface active\n";
        }
    }
}
