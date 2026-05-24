<?php
/**
 * Helpers for ruTorrent plugin maintenance scripts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userLifecycle.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';

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

    $homeRoot = rtrim((string) ($overrides['homeRoot'] ?? '/home'), '/');
    $skelRoot = rtrim((string) ($overrides['skelRoot'] ?? '/etc/skel'), '/');
    $pluginsDir = $homeRoot.'/'.$username.'/www/rutorrent/plugins';
    $paths = array(
        'plugins' => $pluginsDir,
        'diskspace' => $pluginsDir.'/diskspace',
        'hddquotaSource' => $skelRoot.'/www/rutorrent/plugins/hddquota',
        'hddquotaTarget' => $pluginsDir.'/hddquota',
        'accessIni' => $homeRoot.'/'.$username.'/www/rutorrent/conf/access.ini',
    );
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
            foreach (array(
                array('install_hddquota', 'cp -rp '.escapeshellarg($paths['hddquotaSource']).' '.escapeshellarg($paths['plugins'])),
                array('chown_hddquota', 'chown '.escapeshellarg($username.':'.$username).' '.escapeshellarg($paths['hddquotaTarget'])),
                array('chmod_hddquota', 'chmod -R 777 '.escapeshellarg($paths['hddquotaTarget'])),
            ) as $commandSpec) {
                $ok = pmssCheckRutorrentPluginsRunCommand(
                    $username,
                    $commandSpec[0],
                    $commandSpec[1],
                    $runner
                ) === 0 && $ok;
            }
        }
    }

    if (!is_dir(dirname($paths['accessIni']))) {
        return false;
    }

    return pmssReplaceUserFilePreservingMetadata($paths['accessIni'], $accessIni, 0666 & ~umask()) && $ok;
}
