<?php
/**
 * Library for PMSS Updates
 * /scripts/lib/update.php
 *
 * Contains various functions, settings, etc. for use in /scripts/util/update-step2.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/rtorrentConfig.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/update/logging.php';
require_once __DIR__.'/update/apt.php';

// Cache container for os-release parsing so tests can reset it safely.
$GLOBALS['PMSS_OS_RELEASE_CACHE'] = $GLOBALS['PMSS_OS_RELEASE_CACHE'] ?? [];

/**
 * Determine which os-release file to consult (allows tests to override).
 */
function pmssOsReleasePath(): string
{
    // Allow tests to override os-release location while keeping default stable.
    return pmssResolvePathFromEnv('PMSS_OS_RELEASE_PATH', '/etc/os-release');
}

/**
 * Locate the base directory for skeleton files.
 */
function pmssSkeletonBase(): string
{
    return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel');
}

/**
 * Resolve a path inside the skeleton directory.
 */
function pmssSkeletonPath(string $relative): string
{
    return pmssSkeletonBase().'/'.$relative;
}

/**
 * Update a user's file from the skeleton directory.
 *
 * @param string $file The filename relative to the skeleton base and the user's home.
 * @param string $user The username whose file should be updated.
 */
function updateUserFile($file, $user) {
    if (empty($file) || empty($user)) {
        logMessage("[user:{$user}] updateUserFile skipped (invalid params or home missing): {$file}");
        return;
    }

    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $homeDir  = $homeRoot.'/'.$user;
    if (!file_exists($homeDir)) {
        logMessage("[user:{$user}] updateUserFile skipped (home missing): {$file}");
        return;
    }
    if (!is_dir($homeDir)) {
        logMessage("[user:{$user}] updateUserFile skipped (home not a directory): {$file}");
        return;
    }

    $sourceFile = pmssSkeletonPath($file);
    $targetFile = $homeDir.'/'.$file;

    if (!file_exists($sourceFile)) {
        logMessage("[user:{$user}] Source skeleton missing for {$file}");
        return;
    }

    if (!is_file($sourceFile)) {
        logMessage("[user:{$user}] Source skeleton path is not a regular file: {$file}");
        return;
    }
    
    $parent = dirname($targetFile);
    if ($parent === '' || $parent === '.' || $parent === '/') {
        logMessage("[user:{$user}] Invalid parent directory for {$targetFile}");
        return;
    }
    if (!is_dir($parent)) {
        if (file_exists($parent)) {
            logMessage("[user:{$user}] Parent path exists but is not a directory: {$parent}");
            return;
        }

        $prefix = rtrim($homeDir, '/');
        $relative = substr($parent, strlen($prefix));
        $relative = ltrim($relative, '/');
        if ($relative !== '') {
            $path = $prefix;
            foreach (explode('/', $relative) as $segment) {
                if ($segment === '') {
                    continue;
                }
                $path .= '/'.$segment;
                if (is_dir($path)) {
                    continue;
                }
                if (file_exists($path)) {
                    logMessage("[user:{$user}] Cannot create directory, path exists: {$path}");
                    return;
                }
                if (!@mkdir($path, 0755)) {
                    logMessage("[user:{$user}] Failed to create directory: {$path}");
                    return;
                }
                @chmod($path, 0755);
                @chown($path, (string) $user);
                @chgrp($path, (string) $user);
                logMessage("[user:{$user}] Created directory: {$path}");
            }
        }
    }

    if (!is_dir($parent)) {
        return;
    }

    if (!file_exists($targetFile)) {
        copyToUserSpace($sourceFile, $targetFile, $user);
        logMessage("[user:{$user}] Added skeleton file: {$file}");
        return;
    }

    if (!is_file($targetFile)) {
        logMessage("[user:{$user}] Target path is not a regular file, skipping: {$file}");
        return;
    }
    $sourceContent = file_get_contents($sourceFile);
    $targetContent = file_get_contents($targetFile);
    if ($sourceContent === false || $targetContent === false) {
        logMessage("[user:{$user}] Error reading file contents for comparison: {$file}");
        return;
    }
    if (sha1($sourceContent) === sha1($targetContent)) {
        return;
    }

    copyToUserSpace($sourceFile, $targetFile, $user);
    logMessage("[user:{$user}] Updated skeleton file: {$file}");
}

/**
 * Copy a file to a user's home directory and adjust its permissions and ownership.
 *
 * @param string $sourceFile The source file path.
 * @param string $targetFile The target file path in the user's home directory.
 * @param string $user       The username for setting file ownership.
 *
 * @return void
 */
function copyToUserSpace($sourceFile, $targetFile, $user) {
    $parent = dirname($targetFile);
    if (!is_dir($parent)) {
        logMessage("[user:{$user}] Failed to copy; parent directory missing: {$parent}");
        return;
    }

    $tempFile = @tempnam($parent, 'pmss-userfile-');
    if ($tempFile === false) {
        logMessage("[user:{$user}] Failed to create temp file in {$parent}");
        return;
    }
    if (!copy($sourceFile, $tempFile)) {
        @unlink($tempFile);
        logMessage("[user:{$user}] Failed to copy {$sourceFile} to temp file for {$targetFile}");
        return;
    }

    if (!@chmod($tempFile, 0755)) {
        logMessage("[WARN] Failed to chmod 0755: {$targetFile}");
    }
    if (!@chown($tempFile, (string) $user)) {
        logMessage("[WARN] Failed to chown {$user}: {$targetFile}");
    }
    if (!@chgrp($tempFile, (string) $user)) {
        logMessage("[WARN] Failed to chgrp {$user}: {$targetFile}");
    }

    if (!@rename($tempFile, $targetFile)) {
        @unlink($tempFile);
        logMessage("[user:{$user}] Failed to move temp file into place: {$targetFile}");
        return;
    }

    // Avoid shelling out for simple chmod/chown: fork failures are common during updates.
    if (!@chmod($targetFile, 0755)) {
        logMessage("[WARN] Failed to chmod 0755: {$targetFile}");
    }
    if (!@chown($targetFile, (string) $user)) {
        logMessage("[WARN] Failed to chown {$user}: {$targetFile}");
    }
    if (!@chgrp($targetFile, (string) $user)) {
        logMessage("[WARN] Failed to chgrp {$user}: {$targetFile}");
    }
}

/**
 * Update ruTorrent configuration for a given user.
 *
 * This function reads ruTorrent configuration template files,
 * replaces placeholders with user-specific paths, and writes the updated
 * configuration to the user's ruTorrent directory.
 *
 * @param string $username The username for which to update the configuration.
 * @param int    $scgiPort The SCGI port for ruTorrent configuration (currently not used).
 *
 * @return void
 */
function updateRutorrentConfig($username, $scgiPort) {
    $templateConfigPath = '/etc/seedbox/config/template.rutorrent.config';
    $templateAccessPath = '/etc/seedbox/config/template.rutorrent.access';
    
    $rutorrentConfig = file_get_contents($templateConfigPath);
    $accessIni       = file_get_contents($templateAccessPath);
    
    if ($rutorrentConfig === false || $accessIni === false) {
        echo "Failed to read ruTorrent template files.\n";
        return;
    }
    
    // Update ruTorrent configuration with user-specific values.
    $rutorrentConfig = str_replace(
        '$scgi_host = "";',
        '$scgi_host = "unix:///home/' . $username . '/.rtorrent.socket";',
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$tempDirectory = null;',
        "\$tempDirectory = '/home/{$username}/.tmp/';",
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$topDirectory = \'/\';',
        "\$topDirectory = '/home/{$username}/';",
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$log_file = \'/tmp/errors.log\';',
        "\$log_file = '/home/{$username}/www/rutorrent/errors.log';",
        $rutorrentConfig
    );
    
    $configPath = "/home/{$username}/www/rutorrent/conf/config.php";
    $accessPath = "/home/{$username}/www/rutorrent/conf/access.ini";
    
    if (file_put_contents($configPath, $rutorrentConfig) === false) {
        echo "Failed to write ruTorrent config to {$configPath}\n";
        return;
    }
    if (file_put_contents($accessPath, $accessIni) === false) {
        echo "Failed to write ruTorrent access config to {$accessPath}\n";
        return;
    }
}

/**
 * Retrieve and cache OS release data from /etc/os-release.
 *
 * @return array Parsed key-value pairs from /etc/os-release.
 */
function getOsReleaseData() {
    $path = pmssOsReleasePath();
    if (!isset($GLOBALS['PMSS_OS_RELEASE_CACHE'][$path])) {
        $parsed = @parse_ini_file($path);
        $GLOBALS['PMSS_OS_RELEASE_CACHE'][$path] = is_array($parsed) ? $parsed : [];
    }
    return $GLOBALS['PMSS_OS_RELEASE_CACHE'][$path];
}

/**
 * Get the distribution name from /etc/os-release.
 *
 * @return string The distribution ID (e.g., "ubuntu", "debian"), or an empty string if not found.
 */
function getDistroName() {
    $data = getOsReleaseData();
    return isset($data['ID']) ? $data['ID'] : '';
}

/**
 * Get the distribution version from /etc/os-release.
 *
 * Extracts and returns the numeric part of VERSION_ID.
 *
 * @return string The distribution version number, or an empty string if not found.
 */
function getDistroVersion() {
    $data = getOsReleaseData();
    if (isset($data['VERSION_ID'])) {
        if (preg_match('/^([0-9]+)/', $data['VERSION_ID'], $matches)) {
            return $matches[1];
        }
        return $data['VERSION_ID'];
    }
    return '';
}

/**
 * Reset cached os-release data so tests can inject fresh fixtures.
 */
function pmssResetOsReleaseCache(): void
{
    $path = pmssOsReleasePath();
    unset($GLOBALS['PMSS_OS_RELEASE_CACHE'][$path]);
}

/**
 * Get the distribution codename from /etc/os-release when available.
 *
 * @return string Lowercase codename (e.g., "bullseye") or an empty string.
 */
function getDistroCodename(): string
{
    $data = getOsReleaseData();
    if (!empty($data['VERSION_CODENAME'])) {
        return strtolower(trim($data['VERSION_CODENAME']));
    }
    return '';
}

/**
 * Retrieve current PMSS version from the configured version file.
 *
 * @param string $versionFile Path to the version file.
 *
 * @return string The version string or "unknown" if not found.
 */
function getPmssVersion($versionFile = '/etc/seedbox/config/version') {
    $paths = array($versionFile, '/etc/seedbox/runtime/version');
    foreach ($paths as $path) {
        if ($path === '' || !is_file($path)) {
            continue;
        }
        $size = @filesize($path);
        if (!is_int($size) || $size <= 0) {
            continue;
        }
        $data = @file_get_contents($path);
        if (!is_string($data)) {
            continue;
        }
        return trim($data);
    }
    return 'unknown';
}

// Backwards-compatible wrappers for legacy helper names.
function updateAptSources(string $distroName, int $distroVersion, string $currentHash, array $repos, ?callable $logger = null): void
{
    pmssUpdateAptSources($distroName, $distroVersion, $currentHash, $repos, $logger);
}

/** Generate /etc/motd using the template and system details */
function generateMotd(): void
{
    require_once __DIR__.'/motd/Generator.php';
    \Motd::motdGenerate();
}
