<?php
/** GUI directory and managed-file repair; path policy lives in watchdogPaths.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Ensure a required user directory exists with expected ownership.
 *
 * Returns false only when a conflicting non-directory path blocks recovery.
 */
function pmssCheckGuiEnsureUserDirectory(string $directory, string $user, string $label, callable $log, string $homeDir = ''): bool
{
    $homeDir = $homeDir === '' ? dirname($directory) : rtrim($homeDir, '/');
    if (!pmssCheckGuiUserPathIsSafe($directory, $homeDir, true)) {
        $log("Skipping {$user}: unsafe {$label} directory target {$directory}");
        return false;
    }

    if (is_dir($directory)) {
        return true;
    }

    if (file_exists($directory)) {
        $log("Skipping {$user}: expected {$label} directory but found non-directory at {$directory}");
        return false;
    }

    $log("Restoring {$label} directory for user {$user}");
    if (!pmssAccountDirectoryEnsure($user, $homeDir, $directory, 0755, 0755)) {
        $log("Skipping {$user}: unable to create {$label} directory at {$directory}");
        return false;
    }
    return pmssCheckGuiApplyOwnership($directory, $user, $log) && is_dir($directory);
}

/**
 * Identify damaged panel copies without writes or examining more than the skeleton size.
 * A truncation of a newer served version is not detected when the host skeleton is
 * behind; a copy larger than the skeleton is not examined.
 */
function pmssCheckGuiRestoreTargetIsDamaged(string $targetFile, string $skeletonBytes): bool
{
    clearstatcache(true, $targetFile);
    if (!file_exists($targetFile) && !is_link($targetFile)) return true;
    $bytes = pmssReadRegularFileContentsVerified($targetFile, null, strlen($skeletonBytes));
    if ($bytes === null) return false;
    if ($bytes === '') return true;
    if (!function_exists('token_get_all') || !defined('TOKEN_PARSE')) return false;
    try {
        token_get_all($bytes, TOKEN_PARSE);
    } catch (\ParseError $error) {
        return true;
    } catch (\Throwable $error) {
        return false;
    }
    // Inline HTML can still parse after truncation; require an exact shorter prefix.
    return strlen($bytes) < strlen($skeletonBytes)
        && strncmp($bytes, $skeletonBytes, strlen($bytes)) === 0;
}

/**
 * Restore a managed user panel file when it is missing or differs from the skeleton.
 * @param array<string,callable> $ops Optional accountWrite, eligible and deliver seams.
 */
function pmssCheckGuiRestoreUserFile(string $targetFile, string $sourceFile, string $user, string $label, callable $log, string $homeDir = '', array $ops = []): bool
{
    $homeDir = $homeDir === '' ? dirname(dirname($targetFile)) : rtrim($homeDir, '/');
    if (!pmssCheckGuiUserPathIsSafe($targetFile, $homeDir, false)) {
        $log("Skipping {$user}: unsafe {$label} target {$targetFile}");
        return false;
    }

    $targetSize = @filesize($targetFile);
    $sourceSize = @filesize($sourceFile);
    if (is_file($targetFile) && is_int($targetSize) && $targetSize > 0) {
        // Preserve an existing panel file when the skeleton is temporarily unavailable.
        if (!is_int($sourceSize) || $sourceSize <= 0) {
            return true;
        }
        if (pmssPathTargetIsSafe($sourceFile, false, true) && pmssRegularFilePathIsReadable($sourceFile)) {
            $targetHash = @sha1_file($targetFile);
            if ($targetHash !== false && $targetHash === @sha1_file($sourceFile)) {
                return true;
            }
        }
    }

    if (file_exists($targetFile) && !is_file($targetFile)) {
        $log("Skipping {$user}: expected {$label} file but found non-file at {$targetFile}");
        return false;
    }

    if (
        !pmssPathTargetIsSafe($sourceFile, false, true)
        || !pmssRegularFilePathIsReadable($sourceFile)
        || !is_int($sourceSize)
        || $sourceSize <= 0
    ) {
        $log("Cannot restore {$label} for {$user}: missing skeleton source {$sourceFile}");
        return false;
    }

    $content = pmssReadRegularFileContents($sourceFile);
    if ($content === null || strlen($content) !== $sourceSize) {
        $log("Skipping {$user}: unable to read complete {$label} source {$sourceFile}");
        return false;
    }

    $log("Restoring {$label} for user {$user}");
    $existing = @lstat($targetFile);
    $mode = is_array($existing) ? ((int) $existing['mode'] & 0777) : 0644;
    $written = isset($ops['accountWrite'])
        ? $ops['accountWrite']($user, $homeDir, $targetFile, $content, $mode, true)
        : pmssReplaceAccountFile($user, $homeDir, $targetFile, $content, $mode, true);
    if (!$written) {
        // A divergent copy may be newer than this host's skeleton (ADR 0318).
        if (pmssCheckGuiRestoreTargetIsDamaged($targetFile, $content)
            && pmssUpdateUserRootStageFallback($user, $homeDir, $targetFile, $content, ['log' => $log] + $ops) === true) {
            return true;
        }
        $log("Skipping {$user}: unable to atomically replace {$label} at {$targetFile}");
        return false;
    }

    return pmssCheckGuiApplyOwnership($targetFile, $user, $log);
}

/** Preserve the existing public helper contract for index.php callers. */
function pmssCheckGuiRestoreUserIndex(string $targetFile, string $sourceFile, string $user, callable $log, string $homeDir = ''): bool
{
    return pmssCheckGuiRestoreUserFile($targetFile, $sourceFile, $user, 'index.php', $log, $homeDir);
}
