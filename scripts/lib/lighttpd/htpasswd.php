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

    return pmssWriteUserFile($htpasswdPath, implode("\n", $updatedLines)."\n", $owner, 0640);
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
