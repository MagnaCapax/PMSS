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
pmssRequireRelativeFiles(__DIR__, ['version.php', 'update/apt.php', 'update/osRelease.php']);

/**
 * Locate the base directory for skeleton files.
 */
function pmssSkeletonBase(): string
{
    return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel');
}

/**
 * Resolve and validate a tenant home before doing ownership-sensitive writes.
 */
function pmssUpdateUserHomeDir(string $user): string
{
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $homeDir  = $homeRoot.'/'.$user;
    $realRoot = realpath($homeRoot);
    $realHome = realpath($homeDir);
    if ($realRoot === false || $realRoot === '/' || $realHome === false || !is_dir($realHome)) {
        logMessage("[ERROR] Refusing user file update with invalid home for {$user}: {$homeDir}");
        return '';
    }

    $realRoot = rtrim($realRoot, '/');
    $realHome = rtrim($realHome, '/');
    if ($realHome === $realRoot || strpos($realHome, $realRoot.'/') !== 0) {
        logMessage("[ERROR] Refusing user file update outside home root for {$user}: {$homeDir}");
        return '';
    }

    return $realHome;
}

/**
 * Confirm a target resolves strictly below the already-validated tenant home.
 */
function pmssUpdateUserPathIsInsideHome(string $path, string $homeDir): bool
{
    if ($path === '' || $path === '/' || $homeDir === '' || $homeDir === '/') {
        return false;
    }

    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }

    $realPath = rtrim($realPath, '/');
    $homeDir  = rtrim($homeDir, '/');
    return $realPath !== $homeDir && strpos($realPath, $homeDir.'/') === 0;
}

/**
 * Confirm a not-yet-created target would land strictly below the tenant home.
 */
function pmssUpdateUserCandidatePathIsInsideHome(string $path, string $homeDir): bool
{
    return $path !== '' && $path !== '/' && $homeDir !== '' && pmssPathWithinResolvedRoot($path, $homeDir);
}

/**
 * Apply tenant ownership only after proving the resolved target is in that home.
 */
function pmssApplyUserPathPermissions(string $path, string $user, string $homeDir, string $label): bool
{
    if (!pmssUpdateUserPathIsInsideHome($path, $homeDir)) {
        logMessage("[ERROR] Refusing to chown path outside user home for {$user}: {$label}");
        return false;
    }

    if (!@chmod($path, 0755)) {
        logMessage("[WARN] Failed to chmod 0755: {$label}");
    }
    if (!@chown($path, (string) $user)) {
        logMessage("[WARN] Failed to chown {$user}: {$label}");
    }
    if (!@chgrp($path, (string) $user)) {
        logMessage("[WARN] Failed to chgrp {$user}: {$label}");
    }

    return true;
}

/**
 * Restore root:root ownership for critical top-level directories if drift appears.
 *
 * @param callable|null $logger Optional logger for tests or callers.
 * @param string[]|null $paths  Optional path set for hermetic tests.
 */
function pmssEnsureTopLevelRootOwnership(?callable $logger = null, ?array $paths = null): bool
{
    $log = $logger ?: 'logMessage';
    $paths = $paths ?: ['/', '/bin', '/boot', '/dev', '/etc', '/home', '/opt', '/root', '/run', '/sbin', '/srv', '/tmp', '/usr', '/var'];
    $ok = true;
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            continue;
        }

        $stat = @lstat($path);
        if (!is_array($stat)) {
            $log("[WARN] Unable to stat top-level ownership invariant path: {$path}");
            $ok = false;
            continue;
        }
        if ((int) $stat['uid'] === 0 && (int) $stat['gid'] === 0) {
            continue;
        }

        $log("[ERROR] Top-level ownership invariant drift: {$path} is uid={$stat['uid']} gid={$stat['gid']}; restoring root:root");
        pmssLogJson(['event' => 'top_level_ownership_drift', 'path' => $path, 'uid' => (int) $stat['uid'], 'gid' => (int) $stat['gid']]);
        if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            $ok = false;
            $log("[WARN] Dry-run would restore top-level ownership invariant: {$path}");
            continue;
        }

        @chown($path, 'root');
        @chgrp($path, 'root');
        $after = @lstat($path);
        if (!is_array($after) || (int) $after['uid'] !== 0 || (int) $after['gid'] !== 0) {
            $ok = false;
            $log("[ERROR] Top-level ownership invariant still failing after restore attempt: {$path}");
        }
    }

    return $ok;
}

/**
 * Update a user's file from the skeleton directory.
 *
 * @param string $file The filename relative to the skeleton base and the user's home.
 * @param string $user The username whose file should be updated.
 */
function updateUserFile($file, $user) {
    $logUser = is_scalar($user) ? (string) $user : 'invalid';
    $logFile = is_scalar($file) ? (string) $file : 'invalid';
    $logUser = str_replace(["\r", "\n", "\0"], '?', $logUser);
    $logFile = str_replace(["\r", "\n", "\0"], '?', $logFile);
    if (!is_string($file) || !is_string($user) || trim($file) === '' || trim($user) === '') {
        logMessage("[user:{$logUser}] updateUserFile skipped (invalid params or home missing): {$logFile}");
        return;
    }

    if ($user === '.' || $user === '..' || preg_match('#[/\r\n\0]#', $user) === 1) {
        logMessage("[user:{$logUser}] updateUserFile skipped (unsafe user path segment): {$logFile}");
        return;
    }

    if (!pmssPathRelativeStringIsSafe($file)) {
        logMessage("[user:{$logUser}] updateUserFile skipped (unsafe relative path): {$logFile}");
        return;
    }

    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $homeDir  = $homeRoot.'/'.$user;
    $realHomeDir = pmssUpdateUserHomeDir($user);
    if ($realHomeDir === '') {
        logMessage("[user:{$user}] updateUserFile skipped (invalid home): {$file}");
        return;
    }

    $sourceFile = pmssSkeletonBase().'/'.$file;
    $targetFile = $homeDir.'/'.$file;

    if (is_link($targetFile)) {
        logMessage("[user:{$user}] Target path is a symlink, skipping: {$file}");
        return;
    }

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
                if (!pmssApplyUserPathPermissions($path, (string) $user, $realHomeDir, $path)) {
                    return;
                }
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
    $homeDir = pmssUpdateUserHomeDir((string) $user);
    if ($homeDir === '' || !pmssUpdateUserCandidatePathIsInsideHome($targetFile, $homeDir) || is_link($targetFile)) {
        logMessage("[ERROR] Refusing to copy user file outside home for {$user}: {$targetFile}");
        return false;
    }

    if (!is_dir($parent)) {
        logMessage("[user:{$user}] Failed to copy; parent directory missing: {$parent}");
        return false;
    }

    $applyPermissions = static function (string $path) use ($targetFile, $user, $homeDir): bool {
        return pmssApplyUserPathPermissions($path, (string) $user, $homeDir, $targetFile);
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

    if (!$applyPermissions($tempFile)) {
        @unlink($tempFile);
        return false;
    }

    if (!@rename($tempFile, $targetFile)) {
        @unlink($tempFile);
        logMessage("[user:{$user}] Failed to move temp file into place: {$targetFile}");
        return false;
    }

    // Avoid shelling out for simple chmod/chown: fork failures are common during updates.
    return $applyPermissions($targetFile);
}
