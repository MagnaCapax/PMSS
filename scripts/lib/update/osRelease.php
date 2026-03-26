<?php
/**
 * Focused os-release helpers shared by updater libraries and standalone tools.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

$GLOBALS['PMSS_OS_RELEASE_CACHE'] = $GLOBALS['PMSS_OS_RELEASE_CACHE'] ?? [];

/**
 * Retrieve and cache os-release data from the configured path.
 *
 * @return array Parsed key-value pairs from os-release, or an empty array.
 */
function getOsReleaseData()
{
    $path = pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release');
    if (isset($GLOBALS['PMSS_OS_RELEASE_CACHE'][$path])) {
        return $GLOBALS['PMSS_OS_RELEASE_CACHE'][$path];
    }

    $parsed = @parse_ini_file($path);
    return $GLOBALS['PMSS_OS_RELEASE_CACHE'][$path] = is_array($parsed) ? $parsed : [];
}

/**
 * Read the distro identifier from os-release.
 */
function getDistroName()
{
    return (string) (getOsReleaseData()['ID'] ?? '');
}

/**
 * Read the distro major version from os-release.
 */
function getDistroVersion()
{
    $versionId = (string) (getOsReleaseData()['VERSION_ID'] ?? '');
    return $versionId !== '' && preg_match('/^([0-9]+)/', $versionId, $matches) ? $matches[1] : $versionId;
}

/**
 * Reset cached os-release data so tests can inject fresh fixtures.
 */
function pmssResetOsReleaseCache(): void
{
    unset($GLOBALS['PMSS_OS_RELEASE_CACHE'][pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release')]);
}

/**
 * Read the distro codename from os-release when available.
 */
function getDistroCodename(): string
{
    return strtolower(trim((string) (getOsReleaseData()['VERSION_CODENAME'] ?? '')));
}
