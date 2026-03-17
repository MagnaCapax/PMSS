<?php
/**
 * rTorrent and ruTorrent configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../rtorrentConfig.php';
require_once __DIR__.'/traffic.php';

/**
 * Build and write the rTorrent configuration file, returning details for reuse.
 */
function userConfigureRtorrent(array $user): array
{
    echo "Creating rTorrent config\n";
    $resources = [];
    $resourceFile = '/etc/seedbox/config/system.rtorrent.resources';
    if (file_exists($resourceFile)) {
        $resources = unserialize((string)file_get_contents($resourceFile));
    }

    $rtorrentConfig = new rtorrentConfig($resources);
    $throttle = pmssReadTorrentThrottle($user['name']);
    $configuration = $rtorrentConfig->createConfig([
        'ram' => $user['memory'],
        'dht' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht'),
        'pex' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex'),
        'uploadThrottle' => $throttle === null ? 0 : $throttle,
    ]);
    $rtorrentConfig->writeConfig($user['name'], $configuration['configFile']);

    return $configuration;
}
