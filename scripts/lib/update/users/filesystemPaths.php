<?php
/** Home-path guards, obsolete entries and skeleton links. @license GPL-3.0-only */

/**
 * Confirm that a user maintenance path resolves beneath the configured home root.
 */
function pmssUserPathWithinHomeRoot(string $path): bool
{
    return pmssPathWithinResolvedRoot($path, pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'));
}

/**
 * Remove a file or symlink if it still exists.
 */
function pmssUserDeletePathIfPresent(string $path): void
{
    $homeRoot = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/');
    if (!pmssUserPathWithinHomeRoot($path) || strpos($path, $homeRoot.'/') !== 0) return;
    $relative = substr($path, strlen($homeRoot) + 1);
    $user = strtok($relative, '/');
    if (!is_string($user) || !pmssValidateUsername($user) || strpos($relative, '/') === false) return;
    $home = $homeRoot.'/'.$user;
    if (!pmssAccountPathRun($user, $home, [$home.'/.pmss-delete-check'],
        'if [ -e '.escapeshellarg($path).' ] || [ -L '.escapeshellarg($path).' ]; then '
        .'[ ! -d '.escapeshellarg($path).' ] && rm -f -- '.escapeshellarg($path).'; fi')) {
        logMessage("[user:{$user}] Unable to remove obsolete path: {$path}");
    }
}

/** Keep the old installer while a user crontab still executes it. */
function pmssUserRemoveLegacyOpenClawInstaller(string $user, string $home, string $spoolDir = '/var/spool/cron/crontabs'): void
{
    $crontabPath = $spoolDir.'/'.$user;
    $entry = @lstat($crontabPath);
    // A special or unreadable spool entry cannot prove the old cron job is gone.
    if (is_array($entry) && ($entry['mode'] & 0170000) !== 0100000) {
        return;
    }
    $crontab = @file_get_contents($crontabPath);
    if (($crontab === false && $entry !== false)
        || (is_string($crontab) && strpos($crontab, 'install-openclaw.php') !== false)) {
        return;
    }

    pmssUserDeletePathIfPresent($home.'/install-openclaw.php');
}

/**
 * Restore the skeleton's data/watch links without replacing user content.
 */
function pmssUserEnsureWebRootSymlinks(array $ctx): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    $www = $home.'/www';
    if ($user === '' || $home === '' || !is_dir($www) || is_link($www)) {
        return;
    }

    foreach (['data', 'watch'] as $directory) {
        $source = $home.'/'.$directory;
        $link = $www.'/'.$directory;
        $target = '../'.$directory;
        if (!pmssUserPathWithinHomeRoot($source) || !pmssUserPathWithinHomeRoot($link)) {
            continue;
        }
        if (is_link($link)) {
            if (readlink($link) !== $target) {
                logMessage("[user:{$user}] Refusing to replace unexpected www/{$directory} symlink");
            }
            continue;
        }
        if (file_exists($link)) {
            logMessage("[user:{$user}] Refusing to replace existing www/{$directory} path");
            continue;
        }
        if (is_link($source) || (!is_dir($source) && !pmssEnsureUserHomeDir($user, $home, $directory, 0750))) {
            logMessage("[user:{$user}] Unable to prepare home/{$directory} for www/{$directory} symlink");
            continue;
        }
        if (@symlink($target, $link)) {
            logMessage("[user:{$user}] Restored www/{$directory} symlink");
        } else {
            logMessage("[user:{$user}] Unable to restore www/{$directory} symlink");
        }
    }
}
