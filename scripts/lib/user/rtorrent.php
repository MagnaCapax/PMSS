<?php
/**
 * rTorrent and ruTorrent configuration helpers.
 */

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../rtorrentConfig.php';
require_once __DIR__.'/../update.php';

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

    $templateFile = '/etc/seedbox/config/template.rtorrentrc';
    $template = file_exists($templateFile) ? file_get_contents($templateFile) : null;

    $rtorrentConfig = new rtorrentConfig($resources, $template);
    $configuration = $rtorrentConfig->createConfig([
        'ram' => $user['memory'],
        'dht' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht'),
        'pex' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex'),
    ]);
    $rtorrentConfig->writeConfig($user['name'], $configuration['configFile']);

    return $configuration;
}

/**
 * Update ruTorrent configuration files for the selected account.
 */
function userConfigureRutorrent(array $user, array $configuration): void
{
    echo "Changing ruTorrent config\n";
    $scgiPort = $configuration['config']['scgiPort'] ?? 0;
    updateRutorrentConfig($user['name'], $scgiPort);
}

/**
 * Restart rTorrent if the lock file reports an active process.
 */
function userRestartRtorrentIfRunning(array $user): void
{
    $lockFile = sprintf('/home/%s/session/rtorrent.lock', $user['name']);
    if (!file_exists($lockFile)) {
        return;
    }
    $pidChunk = explode(':+', (string)file_get_contents($lockFile));
    $pid = (int) $pidChunk;
    if ($pid > 0) {
        runStep('Restarting rTorrent', sprintf('kill -9 %d', $pid));
    }
}
