<?php
/**
 * addUser: per-user configuration application steps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../passwords.php';

/**
 * Apply the per-user PMSS configuration (quota, rtorrent, vhosts).
 *
 * Note: this helper intentionally exits on fatal provisioning errors to keep
 * the CLI contract stable for operators and automation.
 */
function pmssAddUserUserConfigApply(users $userDb, array $user, string $homePath): void
{
    // Record core attributes in the user config store before provisioning services.
    $userDb->addUser($user['name'], array(
        'ramMiB' => $user['memory'],
        'quota' => $user['quota'],
        'quotaBurst' => round(((float) $user['quota']) * 1.25),
        'rtorrentPort' => 0,    #TODO Choose port here and use that for the userConfig :)
        'billingId' => 0,
        'trafficLimit' => 0,
        'suspended' => false
    ));

    // Assign HTTP server port
    runProvisionStep(
        'Assign lighttpd port',
        sprintf('/scripts/util/portManager.php assign %s lighttpd', escapeshellarg($user['name']))
    );

    // Configure quota, rtorrent and ruTorrent.
    if (runProvisionStep(
        'Apply user configuration',
        sprintf('/scripts/util/userConfig.php %s %s %s',
            escapeshellarg($user['name']),
            escapeshellarg($user['memory']),
            escapeshellarg($user['quota'])
        )
    ) !== 0) {
        logProvisionMessage('FATAL: User configuration failed; aborting provisioning');
        finalizeProvision('FAIL', 'user_config_failed', 1);
        exit(1);
    }

    // Record per-user service ports assigned during configuration.
    $portFiles = [
        'rclone' => $homePath.'/.rclonePort',
        'qbittorrent' => $homePath.'/.qbittorrentPort',
        'deluge' => $homePath.'/.delugePort',
    ];
    foreach ($portFiles as $label => $path) {
        if (!is_file($path)) {
            continue;
        }
        $port = (int) trim((string) @file_get_contents($path));
        if ($port <= 0) {
            continue;
        }
        if ($label === 'deluge') {
            logProvisionMessage('Assigned deluge ports: scgi='.$port.' web='.($port + 1));
            continue;
        }
        logProvisionMessage('Assigned '.$label.' port: '.$port);
    }

    runProvisionStep(
        'Configure lighttpd vhost',
        sprintf('/scripts/util/userConfigLighttpd.php %s', escapeshellarg($user['name']))
    );
    runProvisionStep(
        'Regenerate nginx config',
        sprintf('/scripts/util/createNginxConfig.php --user %s', escapeshellarg($user['name']))
    );

    // Sync qBittorrent password after configuration is complete.
    // Deluge is intentionally excluded: its auth file stores passwords in plaintext,
    // making account password sync a security risk (see GH#211).
    if (!empty($user['password']) && pmssUpdateQbittorrentPassword($user['name'], $user['password'])) {
        logProvisionMessage('Synced qBittorrent password');
    }
}
