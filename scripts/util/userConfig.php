#!/usr/bin/env php
<?php
/**
 * PMSS user reconfiguration helper.
 *
 * Entry point for updating an existing account's quotas, scheduler weights, and
 * service configuration. It chains purpose-built helpers so the orchestration
 * layer remains concise while still enforcing the PMSS baseline on repeated
 * runs.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (['traffic', 'iopsLimit', 'deluge', 'qbittorrent', 'qbittorrentWebUiAuth', 'userConfigStore'] as $module) {
    require_once __DIR__.'/../lib/user/'.$module.'.php';
}
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/user/userConfigCli.php';
require_once __DIR__.'/../lib/user/userConfigRuntime.php';
require_once __DIR__.'/../lib/user/bonusQuota.php';
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';
require_once __DIR__.'/../lib/rtorrentConfig.php';
require_once __DIR__.'/../lib/rutorrent/config.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/welcomeMessage.php';
require_once __DIR__.'/../lib/portManager.php';
require_once __DIR__.'/../lib/user/directories.php';
require_once __DIR__.'/../lib/torrentClients/identity.php';

/**
 * Main entry point for user configuration changes.
 */
['user' => $user, 'expectedHome' => $expectedHome, 'configMode' => $configMode,
    'store' => $store, 'payload' => $payload] = pmssUserConfigPrepare($argv ?? null);
pmssUserConfigLimitsApply($user, $expectedHome);

// Compose a canonical rtorrent configuration and mirror it to companion apps.
echo "Creating rTorrent config\n";
try {
    $resources = pmssReadOptionalSerializedArrayFile('/etc/seedbox/config/system.rtorrent.resources', 'rTorrent resource configuration');
    $rtorrentConfig = new rtorrentConfig($resources);
    $throttle = pmssReadTorrentThrottle($user['name']);
    // Keep the account's reserved scgi port across a reconfigure.
    $configuration = $rtorrentConfig->createConfig(pmssRtorrentPortReservationReusable($payload) + [
        'ram' => $user['memory'],
        'dht' => pmssReadRequiredRegularFile('/etc/seedbox/config/user.rtorrent.defaults.dht', 'rTorrent DHT defaults'),
        'pex' => pmssReadRequiredRegularFile('/etc/seedbox/config/user.rtorrent.defaults.pex', 'rTorrent PEX defaults'),
        'uploadThrottle' => $throttle === null ? 0 : $throttle,
    ]);
} catch (RuntimeException $exception) {
    pmssCliExitWithStderr('Error: '.$exception->getMessage()."\n", 1);
}
$rtorrentConfig->writeConfig($user['name'], $configuration['configFile']);

// Persist derived ports once rTorrent config is generated so termination and
// watchdog tooling read the same source of truth as the reservation path.
$port = (int) ($configuration['config']['scgiPort'] ?? 0);
if ($port > 0 && (!isset($payload['rtorrentPort']) || (int) $payload['rtorrentPort'] !== $port)) {
    $payload['rtorrentPort'] = $port;
    if (!$store->persist($user['name'], $payload)) {
        fwrite(STDERR, "Warning: failed to persist rTorrent ports for {$user['name']}\n");
    }
}
echo "Changing ruTorrent config\n";
updateRutorrentConfig($user['name'], (int) ($payload['rtorrentPort'] ?? 0));
$rclonePortFile = sprintf('/home/%s/.rclonePort', $user['name']);
if (!file_exists($rclonePortFile)) {
    $rclonePort = pmssPortManagerAssignServicePort($user['name'], 'rclone');
    if ($rclonePort === null || !pmssNetworkPortFileWrite($rclonePortFile, $rclonePort, 1024, 65500, 0644)) {
        fwrite(STDERR, "Warning: failed to write rclone port for {$user['name']}\n");
    }
}
userConfigureDeluge($user, $configuration);
$qbittorrentConfigFile = sprintf('/home/%s/.config/qBittorrent/qBittorrent.conf', $user['name']);
if (!file_exists($qbittorrentConfigFile)) {
    $qbittorrentPort = pmssPortManagerAssignServicePort($user['name'], 'qbittorrent');
    if (!pmssEnsureUserHomeDir($user['name'], '/home/'.$user['name'], '.config/qBittorrent', 0770)) {
        fwrite(STDERR, "Warning: failed to create qBittorrent config directory for {$user['name']}\n");
    }
    if ($qbittorrentPort === null) {
        fwrite(STDERR, "Warning: failed to assign qBittorrent port for {$user['name']}\n");
    } else {

        try {
            $qbittorrentTemplate = pmssReadRequiredRegularFile('/etc/seedbox/config/template.qbittorrent.conf', 'qBittorrent template');
        } catch (RuntimeException $exception) {
            pmssCliExitWithStderr('Error: '.$exception->getMessage()."\n", 1);
        }

        $qbittorrentConfig = str_replace(
            ['##username', '##port', '##uploadThrottleLine'],
            [
                $user['name'],
                $qbittorrentPort,
                ($throttle !== null && $throttle > 0) ? 'Connection\\GlobalUPLimit='.(int) $throttle : '',
            ],
            $qbittorrentTemplate
        );
        // A new config always carries a WebUI password line (ADR 0102 sentinel) so no build falls back to its default;
        // the account password synced right after addUser replaces it, and the panel asks for one otherwise.
        $qbittorrentConfig = pmssQbittorrentWebUiAuthForce($qbittorrentConfig);
        if (!pmssWriteUserFile($qbittorrentConfigFile, $qbittorrentConfig, $user['name'], 0600)) {
            fwrite(STDERR, "Warning: failed to write qBittorrent config for {$user['name']}\n");
        } elseif (!pmssQbittorrentThrottleSeedRecord($user['name'], $expectedHome, $throttle ?? 0)) {
            fwrite(STDERR, "Warning: failed to record qBittorrent upload throttle for {$user['name']}\n");
        }
        if (!pmssNetworkPortFileWrite(sprintf('/home/%s/.qbittorrentPort', $user['name']), $qbittorrentPort, 1024, 65500, 0644)) {
            fwrite(STDERR, "Warning: failed to write qBittorrent port for {$user['name']}\n");
        }
    }
}
// Let the watchdog stop a live client before writing its daemon-owned settings.
if (pmssTorrentClientLauncherLivenessPids($user['name'], 'qbittorrent') === []) {
    // Keep the template's legacy seed; the stopped writer also sets the selected build's key.
    pmssQbittorrentApplyUploadThrottle($user['name'], $throttle);
}
if (pmssUserConfigDiskQuotaShouldApply($configMode)) {
    userApplyDiskQuota($user);
}
$lockFile = sprintf('/home/%s/session/rtorrent.lock', $user['name']);
if (file_exists($lockFile)) {
    $pid = pmssUserConfigRtorrentLockPid($lockFile);
    if ($pid !== null && pmssUserConfigRtorrentProcessOwnedBy($pid, $user['id'])) {
        runStep('Restarting rTorrent', sprintf('kill -9 %d', $pid));
    } elseif ($pid !== null) {
        fwrite(STDERR, "Warning: refusing to restart rTorrent for {$user['name']}: lock PID is not an owned rTorrent process\n");
    }
}
if (file_exists('/bin/bash')) {
    runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name'])));
}

pmssUserConfigApplyCgroupAndDocker($user, $store);
