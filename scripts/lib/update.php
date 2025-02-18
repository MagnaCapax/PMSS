<?php
/**
 * Library for PMSS Updates
 * /scripts/lib/update.php
 *
 * Contains various functions, settings, etc. for use in /scripts/util/update-step2.php.
 */

// rTorrent class required
require_once '/scripts/lib/rtorrentConfig.php';

// Global variables
$rtorrentConfig = new rtorrentConfig();
$users          = shell_exec('/scripts/listUsers.php');
$users          = explode("\n", trim($users));
$distroName     = getDistroName();          // Returns the distribution ID (e.g. "debian", "ubuntu")
$distroVersion  = getDistroVersion();       // Returns the distribution version number (numeric part)
$serverHostname = trim(file_get_contents('/etc/hostname')); // Hostname of the server as set in /etc/hostname
$lsbrelease     = trim(shell_exec('/usr/bin/lsb_release -cs'));  // LSB Release codename; may be the best selector for packages

/**
 * Update a user's file from /etc/skel.
 *
 * This function copies a source file from /etc/skel to a user's home directory
 * if it doesn't exist there, or updates it if the contents differ.
 *
 * @param string $file The filename relative to /etc/skel and the user's home.
 * @param string $user The username whose file should be updated.
 *
 * @return void
 */
function updateUserFile($file, $user) {
    if (empty($file) || empty($user) || !file_exists("/home/{$user}")) {
        echo "Invalid parameters, file: {$file} user: {$user}\n";
        return;
    }
    
    $sourceFile = '/etc/skel/' . $file;
    $targetFile = "/home/{$user}/" . $file;
        
    if (!file_exists($sourceFile)) {
        echo "Source file: {$file} is missing\n";
        return;
    }
    
    if (!file_exists($targetFile)) {
        copyToUserSpace($sourceFile, $targetFile, $user);
        echo "Added: {$file} for {$user}\n";
    } else {
        $sourceContent = file_get_contents($sourceFile);
        $targetContent = file_get_contents($targetFile);
        if ($sourceContent === false || $targetContent === false) {
            echo "Error reading file contents for comparison.\n";
            return;
        }
        $sourceChecksum = sha1($sourceContent);
        $targetChecksum = sha1($targetContent);
        if ($sourceChecksum !== $targetChecksum) {
            if (!unlink($targetFile)) {
                echo "Failed to remove old file: {$targetFile}\n";
                return;
            }
            copyToUserSpace($sourceFile, $targetFile, $user);
            echo "Updated: {$file} for {$user}\n";
        }
    }
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
    if (!copy($sourceFile, $targetFile)) {
        echo "Failed to copy {$sourceFile} to {$targetFile}\n";
        return;
    }
    // Set file permissions to 755.
    passthru("chmod 755 " . escapeshellarg($targetFile));
    // Change owner and group to the specified user.
    passthru("chown " . escapeshellarg($user) . ":" . escapeshellarg($user) . " " . escapeshellarg($targetFile));
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
    static $data = null;
    if ($data === null) {
        $data = parse_ini_file('/etc/os-release');
    }
    return $data;
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
