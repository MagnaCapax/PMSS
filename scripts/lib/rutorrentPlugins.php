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
 * Replace the managed ruTorrent access file without following symlinks.
 *
 * Existing mode and ownership are preserved so the safety rail does not change
 * the long-standing on-disk policy for already-managed files.
 */
function pmssCheckRutorrentPluginsWriteAccessIni(string $path, string $content): bool
{
    $mode = null;
    $owner = null;
    $group = null;

    if (is_file($path) && is_array($stat = @stat($path))) {
        $mode = $stat['mode'] & 0777;
        $owner = $stat['uid'];
        $group = $stat['gid'];
    }

    if ($mode === null) {
        $mode = 0666 & ~umask();
    }

    return pmssReplaceUserFile($path, $content, static function (string $tmp) use ($group, $mode, $owner): void {
        @chmod($tmp, $mode);
        if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
            if ($owner !== null) {
                @chown($tmp, $owner);
            }
            if ($group !== null) {
                @chgrp($tmp, $group);
            }
        }
    });
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

    return pmssCheckRutorrentPluginsWriteAccessIni($paths['accessIni'], $accessIni) && $ok;
}
