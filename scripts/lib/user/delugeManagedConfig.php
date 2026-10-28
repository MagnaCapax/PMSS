<?php
/**
 * Deluge maintenance facade; codec and hostlist policy live alongside it.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/delugeConfigCodec.php';
require_once __DIR__.'/delugeHostlist.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/integerSetting.php';
require_once __DIR__.'/../torrentClients/identity.php';

/**
 * Resolve the canonical Deluge core.conf path for a user.
 */
function pmssDelugeConfigPath(string $username): string
{
    return pmssUserHomeFilePath($username, '.config/deluge/core.conf');
}

/**
 * Resolve the canonical Deluge auth path for a user.
 */
function pmssDelugeManagedAuthPath(string $username): string
{
    return dirname(pmssDelugeConfigPath($username)).'/auth';
}

/**
 * Resolve the canonical Deluge hostlist.conf path for a user.
 */
function pmssDelugeHostlistPath(string $username): string
{
    return dirname(pmssDelugeConfigPath($username)).'/hostlist.conf';
}

/**
 * Read the daemon localclient password from a Deluge auth file.
 */
function pmssDelugeManagedAuthLocalclientPasswordRead(string $authPath): string
{
    $content = pmssReadRegularFileContents($authPath);
    if ($content === null || $content === '') {
        return '';
    }

    return preg_match('/^localclient:([^:\r\n]+):[0-9]+$/m', $content, $matches) === 1
        ? $matches[1]
        : '';
}

/**
 * Return the PMSS-managed Deluge core.conf keys and values.
 *
 * Keep this list intentionally small: only shared-host safety limits belong
 * here so maintenance can preserve user-owned settings.
 *
 * @return array<string, int>
 */
function pmssDelugeManagedConfigEntries(): array
{
    return [
        'max_active_downloading' => 5,
        'max_active_limit' => 500,
        'max_connections_global' => 300,
        'max_upload_slots_global' => 15,
        'pre_allocate_storage' => true,
    ];
}

/**
 * Load, transform, and atomically rewrite a user's Deluge core.conf.
 */
function pmssDelugeConfigMutate(string $username, callable $mutator, ?string $configFile = null): bool
{
    $configFile = $configFile ?? pmssDelugeConfigPath($username);
    $updatedRaw = pmssDelugeConfigTransform(pmssReadRegularFileContents($configFile), $mutator);
    if ($updatedRaw === null) {
        return false;
    }

    $mode = @fileperms($configFile);

    return pmssReplaceUserFileWithMetadata(
        $configFile,
        $updatedRaw,
        is_int($mode) ? ($mode & 0777) : 0600,
        $username,
        $username
    );
}

/**
 * Restart Deluge Web UI after hostlist.conf changes so it reloads daemon auth.
 */
function pmssDelugeRestartWebIfEnabled(string $username): void
{
    if (!is_file(pmssUserHomeFilePath($username, '.delugeEnable'))) {
        return;
    }

    pmssTorrentClientNativeStop($username, 'deluge', 15, ['processName' => 'deluge-web']);
}

/**
 * Refresh the PMSS-managed subset of a user's Deluge core.conf.
 */
function pmssDelugeApplyManagedConfig(string $username, ?string $configFile = null): bool
{
    $coreChanged = pmssDelugeConfigMutate(
        $username,
        static function (array $config): array {
            return array_replace($config, pmssDelugeManagedConfigEntries());
        },
        $configFile
    );
    $hostlistChanged = pmssDelugeHostlistSyncLocalclientPassword($username);
    if ($hostlistChanged) {
        pmssDelugeRestartWebIfEnabled($username);
    }

    return $coreChanged || $hostlistChanged;
}
