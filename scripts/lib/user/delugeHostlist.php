<?php
/** Localclient hostlist synchronization; loaded through delugeManagedConfig.php.
 * @license GPL-3.0-only
 */

/**
 * Return true for the hostlist entry Deluge Web UI uses for the local daemon.
 *
 * @param mixed $host
 * @param mixed $login
 */
function pmssDelugeHostlistEntryIsLocalclient($host, $login): bool
{
    return is_string($host)
        && is_string($login)
        && $login === 'localclient'
        && in_array($host, ['127.0.0.1', 'localhost'], true);
}

/**
 * Synchronize hostlist.conf with the current daemon localclient password.
 */
function pmssDelugeHostlistSyncLocalclientPassword(string $username, ?string $hostlistFile = null, ?string $authFile = null): bool
{
    $authFile = $authFile ?? pmssDelugeManagedAuthPath($username);
    $localclientPassword = pmssDelugeManagedAuthLocalclientPasswordRead($authFile);
    if ($localclientPassword === '') {
        return false;
    }

    $hostlistFile = $hostlistFile ?? pmssDelugeHostlistPath($username);
    $updatedRaw = pmssDelugeConfigTransform(pmssReadRegularFileContents($hostlistFile), static function (array $config) use ($localclientPassword): array {
        if (!is_array($config['hosts'] ?? null)) {
            return $config;
        }
        foreach ($config['hosts'] as $index => $entry) {
            if (is_array($entry) && count($entry) >= 5
                && pmssDelugeHostlistEntryIsLocalclient($entry[1] ?? null, $entry[3] ?? null)
                && ($entry[4] ?? null) !== $localclientPassword) {
                $entry[4] = $localclientPassword;
                $config['hosts'][$index] = $entry;
            }
        }
        return $config;
    });
    return $updatedRaw !== null
        && pmssReplaceUserFilePreservingMetadata($hostlistFile, $updatedRaw, 0644);
}
