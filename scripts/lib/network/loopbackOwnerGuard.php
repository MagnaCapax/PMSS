<?php
/** Root-registry inputs for the loopback service owner guard. @license GPL-3.0-only */

require_once __DIR__.'/../portManager.php';
require_once __DIR__.'/rules.php';

/**
 * Read native and Docker WebUI reservations only; never allocate or read home display files.
 * An absent entry means the account has no such listener and is skipped silently; an unsafe
 * directory or an unsafe or invalid entry logs one line.
 *
 * @return array<string,array<int,int>> Account names mapped to registered WebUI ports.
 */
function networkQbittorrentWebUiPortsRead(array $userUids, ?string $portDir = null, ?callable $logger = null): array
{
    $portDir = $portDir ?? pmssPortManagerReservationPath();
    $logger = $logger ?? static function (string $message): void {
        fwrite(STDERR, 'makeMonitoringRules: '.$message."\n");
    };
    // Trust only the root-owned registry, without creating or repairing its directory.
    $directory = pmssPathTargetIsSafe($portDir, true) ? @lstat($portDir) : false;
    $safeDirectory = is_array($directory)
        && ($directory['mode'] & 0170000) === 0040000 && $directory['uid'] === 0
        && ($directory['mode'] & 0022) === 0;
    if (!$safeDirectory) {
        if ($userUids) $logger("Skipping qBittorrent loopback owner guard: registry directory {$portDir} is missing or unsafe");
        return [];
    }
    $ports = [];
    foreach ($userUids as $user => $uid) {
        // The accounting map also contains system accounts, which have no managed WebUI.
        if (!is_string($user) || !pmssValidateUsername($user)) continue;
        if (networkMonitoringOwnerUid($uid) === null) {
            $logger("Skipping qBittorrent loopback owner guard for {$user}: invalid uid");
            continue;
        }
        foreach (['qbittorrent', 'media-stack-qbittorrent'] as $service) {
            $path = $portDir.'/'.$service.'-'.$user;
            if (!pmssPathExistsOrLink($path)) continue;
            $metadata = null;
            $raw = pmssReadRegularFileContentsVerified($path, 0, 32, $metadata);
            $port = $raw !== null && ($metadata['mode'] & 0022) === 0
                ? pmssNetworkPortParseDigits($raw, 1024) : null;
            if ($port === null) {
                $logger("Skipping {$service} loopback owner guard for {$user}: unsafe or invalid registry entry");
                continue;
            }
            $ports[$user][] = $port;
        }
    }
    return $ports;
}
