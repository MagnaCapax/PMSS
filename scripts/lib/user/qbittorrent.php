<?php
/**
 * qBittorrent configuration helpers.
 */

require_once __DIR__.'/helpers.php';

function userConfigureQbittorrent(array $user): void
{
    $configDir  = sprintf('/home/%s/.config/qBittorrent', $user['name']);
    $configFile = $configDir.'/qBittorrent.conf';
    if (file_exists($configFile)) {
        return;
    }

    $template = file_get_contents('/etc/seedbox/config/template.qbittorrent.conf');
    $port = (int) round(rand(1500, 65500));
    if (!file_exists($configDir)) {
        mkdir($configDir, 0770, true);
    }
    $config = str_replace(['##username', '##port'], [$user['name'], $port], $template);
    file_put_contents($configFile, $config);
    file_put_contents(sprintf('/home/%s/.qbittorrentPort', $user['name']), $port);
}
