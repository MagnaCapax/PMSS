<?php
/**
 * addUser: per-user configuration application steps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../qbittorrent.php';
require_once __DIR__.'/../userConfigCli.php';
/**
 * Build the canonical userConfig command for addUser provisioning.
 */
function pmssAddUserBuildUserConfigCommand(array $user): string
{
    $command = [
        '/scripts/util/userConfig.php',
        (string) $user['name'],
        (string) $user['memory'],
        (string) $user['quota'],
    ];
    $command = array_merge($command, pmssUserConfigCliBuildUserConfigPositionals($user));
    if (isset($user['torrentThrottle']) && is_numeric($user['torrentThrottle'])) {
        $command[] = '--upload-throttle-kib='.(string) $user['torrentThrottle'];
    }
    if (isset($user['iopsLimit']) && is_numeric($user['iopsLimit'])) {
        $command[] = '--iops-limit='.(string) $user['iopsLimit'];
    }
    if (isset($user['dockerEnabled']) && $user['dockerEnabled'] !== '') {
        $command[] = '--docker-enabled='.(string) $user['dockerEnabled'];
    }
    return implode(' ', array_map('escapeshellarg', $command));
}

/**
 * Apply the per-user PMSS configuration (quota, rtorrent, vhosts).
 *
 * Note: this helper intentionally exits on fatal provisioning errors to keep
 * the CLI contract stable for operators and automation.
 */
function pmssAddUserUserConfigApply(users $userDb, array $user, string $homePath): void
{
    // Record core attributes in the user config store before provisioning services.
    $payload = array(
        'ramMiB' => $user['memory'],
        'quota' => $user['quota'],
        'quotaBurst' => round(((float) $user['quota']) * 1.25),
        'rtorrentPort' => 0,    #TODO Choose port here and use that for the userConfig :)
        'billingServiceId' => 0,
        'billingClientId' => 0,
        'trafficLimit' => 0,
        'suspended' => false
    );
    if (isset($user['trafficCapMbit']) && is_numeric($user['trafficCapMbit'])) {
        $payload['trafficCapMbit'] = (int) $user['trafficCapMbit'];
    }
    if (isset($user['dockerEnabled']) && $user['dockerEnabled'] !== '') {
        $payload['dockerEnabled'] = $user['dockerEnabled'];
    }
    $userDb->addUser($user['name'], $payload);

    // Assign HTTP server port
    runProvisionStep(
        'Assign lighttpd port',
        sprintf('/scripts/util/portManager.php assign %s lighttpd', escapeshellarg($user['name']))
    );

    // Configure quota, rtorrent and ruTorrent.
    $userConfigCmd = pmssAddUserBuildUserConfigCommand($user);
    pmssAddUserRunRequiredProvisionStep(
        'Apply user configuration',
        $userConfigCmd,
        'User configuration failed; aborting provisioning',
        'user_config_failed'
    );
    if (function_exists('pmssAddUserFailureRollbackMarkUserConfigApplied')) {
        pmssAddUserFailureRollbackMarkUserConfigApplied();
    }

    // Record per-user service ports assigned during configuration.
    $portFiles = [
        'rclone' => $homePath.'/.rclonePort',
        'qbittorrent' => $homePath.'/.qbittorrentPort',
        'deluge' => $homePath.'/.delugePort',
    ];
    foreach ($portFiles as $label => $path) {
        $port = pmssReadRegularFileInt($path);
        if ($port <= 0) {
            continue;
        }
        logProvisionMessage($label === 'deluge'
            ? 'Assigned deluge ports: scgi='.$port.' web='.($port + 1)
            : 'Assigned '.$label.' port: '.$port);
    }

    runProvisionStep(
        'Configure lighttpd vhost',
        sprintf('/scripts/util/userConfigLighttpd.php %s', escapeshellarg($user['name']))
    );
    runProvisionStep(
        'Regenerate nginx config',
        sprintf('/scripts/util/createNginxConfig.php --user %s', escapeshellarg($user['name']))
    );
    if (!is_file('/etc/nginx/users/'.$user['name'])) {
        pmssAddUserFatalExit('FAIL', 'nginx config missing after regeneration; aborting provisioning', 'nginx_config_missing');
    }

    // Sync qBittorrent password after configuration is complete.
    // Deluge is intentionally excluded: its auth file stores passwords in plaintext,
    // making account password sync a security risk (see GH#211).
    if (!empty($user['password']) && pmssUpdateQbittorrentPassword($user['name'], $user['password'])) {
        logProvisionMessage('Synced qBittorrent password');
    }
}
