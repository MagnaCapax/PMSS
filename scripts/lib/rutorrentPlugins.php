<?php
/**
 * Helpers for ruTorrent plugin maintenance scripts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userLifecycle.php';

/**
 * Build the fixed ruTorrent plugin paths for a managed user.
 *
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function pmssCheckRutorrentPluginsUserPaths(string $username, array $overrides = array()): array
{
    $homeRoot = rtrim((string) ($overrides['homeRoot'] ?? '/home'), '/');
    $skelRoot = rtrim((string) ($overrides['skelRoot'] ?? '/etc/skel'), '/');

    $pluginsDir = $homeRoot.'/'.$username.'/www/rutorrent/plugins';
    $confDir = $homeRoot.'/'.$username.'/www/rutorrent/conf';

    return array(
        'plugins' => $pluginsDir,
        'diskspace' => $pluginsDir.'/diskspace',
        'hddquotaSource' => $skelRoot.'/www/rutorrent/plugins/hddquota',
        'hddquotaTarget' => $pluginsDir.'/hddquota',
        'accessIni' => $confDir.'/access.ini',
    );
}

/**
 * Run a maintenance command through the shared user lifecycle logger.
 */
function pmssCheckRutorrentPluginsRunCommand(string $username, string $step, string $command, $runner = null): int
{
    if (is_callable($runner)) {
        return (int) $runner($command, $step, $username);
    }

    return pmssUserLifecycleStep('rutorrent_plugins', $username, $step, $command, false);
}

/**
 * Synchronize legacy ruTorrent plugin state for one managed user.
 *
 * @param array<string, string> $overrides
 */
function pmssCheckRutorrentPluginsSyncUser(string $username, string $accessIni, array $overrides = array(), $runner = null): bool
{
    if (!pmssValidateUsername($username)) {
        fwrite(STDERR, 'Skipping invalid username: '.substr($username, 0, 20)."\n");
        return false;
    }

    $paths = pmssCheckRutorrentPluginsUserPaths($username, $overrides);
    if (!is_dir($paths['plugins'])) {
        return false;
    }

    $ok = true;

    if (file_exists($paths['diskspace'])) {
        echo "Disk space exists - deleting!\n";
        $rc = pmssCheckRutorrentPluginsRunCommand(
            $username,
            'remove_diskspace',
            'rm -rf '.escapeshellarg($paths['diskspace']),
            $runner
        );
        $ok = $ok && $rc === 0;
    }

    if (!file_exists($paths['hddquotaTarget'])) {
        echo "HDD Quota does not exist - adding!\n";
        if (!is_dir($paths['hddquotaSource'])) {
            $ok = false;
        } else {
            $copyRc = pmssCheckRutorrentPluginsRunCommand(
                $username,
                'install_hddquota',
                'cp -rp '.escapeshellarg($paths['hddquotaSource']).' '.escapeshellarg($paths['plugins']),
                $runner
            );
            $chownRc = pmssCheckRutorrentPluginsRunCommand(
                $username,
                'chown_hddquota',
                'chown '.escapeshellarg($username.':'.$username).' '.escapeshellarg($paths['hddquotaTarget']),
                $runner
            );
            $chmodRc = pmssCheckRutorrentPluginsRunCommand(
                $username,
                'chmod_hddquota',
                'chmod -R 777 '.escapeshellarg($paths['hddquotaTarget']),
                $runner
            );
            $ok = $ok && $copyRc === 0 && $chownRc === 0 && $chmodRc === 0;
        }
    }

    if (!is_dir(dirname($paths['accessIni']))) {
        return false;
    }

    return @file_put_contents($paths['accessIni'], $accessIni) !== false && $ok;
}
