<?php
/**
 * Deluge configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
require_once __DIR__.'/traffic.php';
require_once __DIR__.'/passwords.php';

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
    $ownedDirs = [
        'Deluge unfinished' => $unfinishedDir,
        'Deluge session'    => $sessionDir,
    ];
    foreach ($ownedDirs as $label => $dir) {
        if (!file_exists($dir)) {
            runStep('Creating '.$label.' dir', sprintf('mkdir -p %s', escapeshellarg($dir)));
            runStep('Fixing '.$label.' ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username.':'.$username), escapeshellarg($dir)));
        }
    }

    $scgiPort    = $configuration['config']['scgiPort'] ?? 5000;
    $existingPort = file_exists("$home/.delugePort") ? (int) file_get_contents("$home/.delugePort") : 0;
    $delugePort   = ($existingPort >= 1024 && $existingPort <= 65000) ? $existingPort : $scgiPort;
    $throttle = pmssReadTorrentThrottle($username);
    $uploadThrottle = ($throttle !== null && $throttle > 0) ? (string) $throttle : '-1.0';

    $coreTemplate = file_get_contents('/etc/seedbox/config/template.deluge.core.conf');
    $coreConfig   = str_replace(
        ['##USERNAME##', '##CACHE', '##DAEMONPORT', '##UPLOAD_THROTTLE##'],
        [$username, (int) ($user['memory'] * 1024 / 16), $delugePort, $uploadThrottle],
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
    $webConfPath = "$configDir/web.conf";
    $existingWebConfig = @file_get_contents($webConfPath);
    $webConfig   = str_replace(['##WEBPORT', '##USER'], [$delugePort + 1, $username], $webTemplate);
    $webConfChanged = is_string($existingWebConfig) && $existingWebConfig !== $webConfig;
    file_put_contents($webConfPath, $webConfig);
    file_put_contents("$home/.delugePort", $delugePort);

    if (!file_exists("$configDir/auth")) {
        runStep('Provisioning Deluge auth template', sprintf('cp %s %s',
            escapeshellarg('/etc/seedbox/config/template.deluge.auth'),
            escapeshellarg("$configDir/auth")
        ));
    }
    pmssEnsureDelugeServicePassword($username);

    if (!file_exists("$configDir/web.conf")) {
        runStep('Provisioning Deluge web template', sprintf('cp %s %s',
            escapeshellarg('/etc/seedbox/config/template.deluge.web.conf'),
            escapeshellarg("$configDir/web.conf")
        ));
    }
    runStep('Fixing Deluge ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username.':'.$username), escapeshellarg("$home/.config/")));

    // If the web config changed, restart deluge-web so base/port changes take effect.
    // Cron (checkDelugeInstances.php) will start it again when Deluge is enabled.
    if ($webConfChanged && file_exists("$home/.delugeEnable")) {
        runStep('Restarting Deluge Web UI (config changed)', sprintf(
            'killall -u %s -TERM deluge-web 2>/dev/null || true',
            escapeshellarg($username)
        ));
    }
}

/**
 * Update Deluge core.conf with the current upload throttle, returning true on change.
 */
function pmssDelugeApplyUploadThrottle(string $username, ?int $throttle = null): bool
{
    $configFile = "/home/{$username}/.config/deluge/core.conf";
    if (!is_file($configFile) || is_link($configFile)) {
        return false;
    }

    $config = file_get_contents($configFile);
    if ($config === false) {
        return false;
    }

    if ($throttle === null) {
        $throttle = pmssReadTorrentThrottle($username);
    }
    $value = ($throttle !== null && $throttle > 0) ? (string) $throttle : '-1.0';
    $updated = preg_replace('/"max_upload_speed"\\s*:\\s*-?[0-9.]+/', '"max_upload_speed": '.$value, $config, 1, $count);

    return $count > 0
        && $updated !== null
        && $updated !== $config
        && file_put_contents($configFile, $updated) !== false;
}
