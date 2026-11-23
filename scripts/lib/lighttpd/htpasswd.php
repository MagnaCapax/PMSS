<?php
/**
 * Htpasswd synchronization helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
pmssRequireRelativeFiles(__DIR__, ['userFileWrite.php', '../user/shadow.php']);

/**
 * Write a crypt()-compatible htpasswd entry for a managed user.
 *
 * Existing entries for the same user are replaced atomically so repeated syncs
 * converge on one credential line without duplicating the account.
 */
function pmssUserHtpasswdHashWrite(string $htpasswdPath, string $username, string $passwordHash, string $owner): bool
{
    $username = trim($username);
    if (
        !pmssUserFilePathIsSafe($htpasswdPath)
        || $username === ''
        || $passwordHash === ''
        || $owner === ''
        || strpos($username, ':') !== false
        || strpos($passwordHash, ':') !== false
        || preg_match('/[\r\n\0]/', $username) === 1
        || preg_match('/[\r\n\0]/', $passwordHash) === 1
    ) {
        return false;
    }

    $lines = array();
    if (is_file($htpasswdPath)) {
        if (is_link($htpasswdPath)) {
            return false;
        }

        $lines = @file($htpasswdPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return false;
        }
    }

    $entry = $username.':'.$passwordHash;
    $updatedLines = array();
    $replaced = false;
    foreach ($lines as $line) {
        if (!is_string($line)) {
            continue;
        }

        if (strpos($line, $username.':') === 0) {
            if (!$replaced) {
                $updatedLines[] = $entry;
                $replaced = true;
            }
            continue;
        }

        $updatedLines[] = $line;
    }

    if (!$replaced) {
        $updatedLines[] = $entry;
    }

    $content = implode("\n", $updatedLines)."\n";
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($owner) : false;
    if (is_array($account) && is_file($htpasswdPath) && !is_link($htpasswdPath)
        && @fileowner($htpasswdPath) !== $account['uid']) {
        // Existing installations can carry an older owner; normalize it once.
        return pmssWriteUserFile($htpasswdPath, $content, $owner, 0640);
    }

    return pmssReplaceAccountFile($owner, dirname(dirname($htpasswdPath)), $htpasswdPath, $content, 0640);
}

/**
 * Mirror the active shadow hash into the per-user htpasswd file.
 *
 * Lighttpd's htpasswd backend accepts crypt()-compatible hashes, so matching
 * the unlocked shadow entry restores one canonical credential after unsuspend.
 */
function pmssUserHtpasswdSyncFromShadow(string $username, string $shadowPath = '/etc/shadow', ?string $htpasswdPath = null): bool
{
    $homeRoot = pmssDirPathResolve(null, 'PMSS_HOME_DIR', '/home');

    $passwordHash = pmssUserShadowPasswordHashRead($username, $shadowPath);
    if ($passwordHash === '') {
        return false;
    }

    if ($htpasswdPath === null || trim($htpasswdPath) === '') {
        $htpasswdPath = $homeRoot.'/'.$username.'/.lighttpd/.htpasswd';
    }

    return pmssUserHtpasswdHashWrite($htpasswdPath, $username, $passwordHash, $username);
}

/**
 * Restore a missing per-user .htpasswd from the account's shadow hash.
 *
 * Panel login reads this file, and nothing else rebuilds it once lighttpd
 * support files are regenerated around it, so the account looks healthy while
 * every login is refused. Only an absent entry counts as missing: an existing
 * file, link or directory is left to the writer's own refusals, and a missing
 * .lighttpd directory is left to config regeneration. An unusable (locked or
 * empty) hash writes nothing. Returns true only when the file was written.
 */
function pmssUserHtpasswdRestoreIfMissing(string $username, string $homeDir, string $shadowPath = '/etc/shadow'): bool
{
    $htpasswdPath = rtrim($homeDir, '/').'/.lighttpd/.htpasswd';
    clearstatcache(true, $htpasswdPath);
    if (@lstat($htpasswdPath) !== false) {
        return false;
    }
    $lighttpdDir = dirname($htpasswdPath);
    if (!is_dir($lighttpdDir) || is_link($lighttpdDir)) {
        return false;
    }

    return pmssUserHtpasswdSyncFromShadow($username, $shadowPath, $htpasswdPath);
}