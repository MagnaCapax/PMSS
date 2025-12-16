<?php
/**
 * Deluge configuration helpers.
 */

require_once __DIR__.'/../update/runtime/commands.php';

function userConfigureDeluge(array $user, array $configuration): void
{
    $username = $user['name'];
    $home = "/home/{$username}";
    $configDir     = "$home/.config/deluge";
    $unfinishedDir = "$home/dataUnfinished";
    $sessionDir    = "$home/.sessionDeluge";

    if (!file_exists($configDir)) {
        runStep('Creating Deluge config dir', sprintf('mkdir -p %s', escapeshellarg($configDir)));
    }
    if (!file_exists($unfinishedDir)) {
        runStep('Creating Deluge unfinished dir', sprintf('mkdir -p %s', escapeshellarg($unfinishedDir)));
        runStep('Fixing Deluge unfinished ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username.':'.$username), escapeshellarg($unfinishedDir)));
    }
    if (!file_exists($sessionDir)) {
        runStep('Creating Deluge session dir', sprintf('mkdir -p %s', escapeshellarg($sessionDir)));
        runStep('Fixing Deluge session ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username.':'.$username), escapeshellarg($sessionDir)));
    }

    $scgiPort    = $configuration['config']['scgiPort'] ?? 5000;
    $existingPort = file_exists("$home/.delugePort") ? (int) file_get_contents("$home/.delugePort") : 0;
    $delugePort   = ($existingPort >= 1024 && $existingPort <= 65000) ? $existingPort : $scgiPort;

    $coreTemplate = file_get_contents('/etc/seedbox/config/template.deluge.core.conf');
    $coreConfig   = str_replace(
        ['##USERNAME##', '##CACHE', '##DAEMONPORT'],
        [$username, (int) ($user['memory'] * 1024 / 16), $delugePort],
        $coreTemplate
    );
    file_put_contents("$configDir/core.conf", $coreConfig);

    $hostlistTemplate = file_get_contents('/etc/seedbox/config/template.deluge.hostlist.conf');
    $hostlistConfig   = str_replace('##DAEMONPORT', $delugePort, $hostlistTemplate);
    file_put_contents("$configDir/hostlist.conf", $hostlistConfig);
    if (!file_exists("$configDir/hostlist.conf.1.2")) {
        @symlink("$configDir/hostlist.conf", "$configDir/hostlist.conf.1.2");
    }

    $webTemplate = file_get_contents('/etc/seedbox/config/template.deluge.web.conf');
    $webConfig   = str_replace(['##WEBPORT', '##USER'], [$delugePort + 1, $username], $webTemplate);
    file_put_contents("$configDir/web.conf", $webConfig);
    file_put_contents("$home/.delugePort", $delugePort);

    if (!file_exists("$configDir/auth")) {
        runStep('Provisioning Deluge auth template', sprintf('cp %s %s',
            escapeshellarg('/etc/seedbox/config/template.deluge.auth'),
            escapeshellarg("$configDir/auth")
        ));
    }
    if (!file_exists("$configDir/web.conf")) {
        runStep('Provisioning Deluge web template', sprintf('cp %s %s',
            escapeshellarg('/etc/seedbox/config/template.deluge.web.conf'),
            escapeshellarg("$configDir/web.conf")
        ));
    }
    runStep('Fixing Deluge ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username.':'.$username), escapeshellarg("$home/.config/")));
}
