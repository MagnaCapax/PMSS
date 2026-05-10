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
require_once __DIR__.'/rutorrent/config.php';
// Bootstrap the structured update logger before the generic runtime fallback
// so update helpers always keep the context-aware logging contract.
require_once __DIR__.'/update/logging.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/update/apt.php';
require_once __DIR__.'/update/osRelease.php';

/**
 * Locate the base directory for skeleton files.
 */
function pmssSkeletonBase(): string
{
    return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel');
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

    $sourceFile = pmssSkeletonBase().'/'.$file;
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
        if (copyToUserSpace($sourceFile, $targetFile, $user)) {
            logMessage("[user:{$user}] Added skeleton file: {$file}");
        }
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

    if (copyToUserSpace($sourceFile, $targetFile, $user)) {
        logMessage("[user:{$user}] Updated skeleton file: {$file}");
    }
}

/**
 * Copy a file to a user's home directory and adjust its permissions and ownership.
 *
 * @param string $sourceFile The source file path.
 * @param string $targetFile The target file path in the user's home directory.
 * @param string $user       The username for setting file ownership.
 *
 * @return bool True when the file was copied into place.
 */
function copyToUserSpace($sourceFile, $targetFile, $user) {
    $parent = dirname($targetFile);
    if (!is_dir($parent)) {
        logMessage("[user:{$user}] Failed to copy; parent directory missing: {$parent}");
        return false;
    }

    $applyPermissions = static function (string $path) use ($targetFile, $user): void {
        if (!@chmod($path, 0755)) {
            logMessage("[WARN] Failed to chmod 0755: {$targetFile}");
        }
        if (!@chown($path, (string) $user)) {
            logMessage("[WARN] Failed to chown {$user}: {$targetFile}");
        }
        if (!@chgrp($path, (string) $user)) {
            logMessage("[WARN] Failed to chgrp {$user}: {$targetFile}");
        }
    };

    $tempFile = @tempnam($parent, 'pmss-userfile-');
    if ($tempFile === false) {
        logMessage("[user:{$user}] Failed to create temp file in {$parent}");
        return false;
    }
    if (!copy($sourceFile, $tempFile)) {
        @unlink($tempFile);
        logMessage("[user:{$user}] Failed to copy {$sourceFile} to temp file for {$targetFile}");
        return false;
    }

    $applyPermissions($tempFile);

    if (!@rename($tempFile, $targetFile)) {
        @unlink($tempFile);
        logMessage("[user:{$user}] Failed to move temp file into place: {$targetFile}");
        return false;
    }

    // Avoid shelling out for simple chmod/chown: fork failures are common during updates.
    $applyPermissions($targetFile);
    return true;
}

/**
 * Retrieve current PMSS version from the configured version file.
 *
 * @param string $versionFile Path to the version file.
 *
 * @return string The version string or "unknown" if not found.
 */
function getPmssVersion($versionFile = '/etc/seedbox/config/version') {
    foreach ([$versionFile, '/etc/seedbox/runtime/version'] as $path) {
        if ($path !== '' && is_file($path) && ($data = trim((string) @file_get_contents($path))) !== '') {
            return $data;
        }
    }

    return 'unknown';
}
