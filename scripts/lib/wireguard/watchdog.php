<?php
/** WireGuard watchdog configuration preflight and service-state dispatch.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../cli/optionParser.php';
require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/../wireguard.php';
pmssRequireRelativeFiles(__DIR__, ['watchdogCommands.php', 'watchdogPeers.php']);

/** Execute the WireGuard health check once. */
function pmssWireguardCheckMain(array $argv): int
{
    [$debug] = pmssCliArgvDebugSplit($argv);

    $logPrefix = date('c') . ' ';
    $configPath = pmssWireguardCheckConfigPath();
    $peerResult = pmssWireguardPeerUsersFromConfig($configPath);
    $peerStatus = (string) ($peerResult['status'] ?? 'unreadable');
    $peerUsers = is_array($peerResult['users'] ?? null) ? $peerResult['users'] : [];
    if ($peerStatus !== 'ok') {
        if ($peerStatus !== 'missing' || $debug) {
            echo $logPrefix . "wireguard config {$peerStatus}; skipping check\n";
        }
        return 0;
    }

    $module = pmssWireguardCommandCapture('lsmod', []);
    if ($module['rc'] !== 0 || !pmssWireguardLsmodOutputHasModule($module['stdout'])) {
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

    if (pmssWireguardCommandCapture('wg', ['show'])['rc'] !== 0) {
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
