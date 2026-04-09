<?php
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/qbittorrent.php';

/**
 * Password synchronization helpers for torrent clients.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Generate a high-entropy Deluge service password.
 */
function pmssDelugeServicePasswordGenerate(int $length = 24): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($position = 0; $position < $length; $position++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

/**
 * Resolve the Deluge auth file for a user.
 */
function pmssDelugeAuthPath(string $username): string
{
    $homeRoot = getenv('PMSS_HOME_DIR');
    if (!is_string($homeRoot) || trim($homeRoot) === '') {
        $homeRoot = '/home';
    }

    return rtrim($homeRoot, '/').'/'.$username.'/.config/deluge/auth';
}

/**
 * Read the localclient password from a Deluge auth file.
 */
function pmssDelugeAuthReadLocalclientPassword(string $authPath): string
{
    if (!is_file($authPath) || is_link($authPath)) {
        return '';
    }

    $content = @file_get_contents($authPath);
    if (!is_string($content) || $content === '') {
        return '';
    }

    return preg_match('/^localclient:([^:\r\n]+):[0-9]+$/m', $content, $matches) === 1
        ? $matches[1]
        : '';
}

/**
 * Write or replace the localclient password in a Deluge auth file.
 */
function pmssDelugeAuthWriteLocalclientPassword(string $authPath, string $password): bool
{
    if ($password === '' || strpos($password, ':') !== false || preg_match('/[\r\n]/', $password) === 1) {
        return false;
    }
    if (!pmssUserFilePathIsSafe($authPath)) {
        return false;
    }

    $lines = @file($authPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $lines = [];
    }

    $replaced = false;
    foreach ($lines as $index => $line) {
        if (preg_match('/^localclient:[^:\r\n]*:[0-9]+$/', $line) === 1) {
            $lines[$index] = 'localclient:'.$password.':10';
            $replaced = true;
            break;
        }
    }

    if (!$replaced) {
        $lines[] = 'localclient:'.$password.':10';
    }

    if (!pmssAtomicWriteFile($authPath, implode("\n", $lines)."\n", 0600)) {
        return false;
    }

    return true;
}

/**
 * Read the legacy template default so provisioning can rotate away from it.
 */
function pmssDelugeTemplateLocalclientPassword(): string
{
    $templatePath = getenv('PMSS_DELUGE_AUTH_TEMPLATE_PATH');
    if (!is_string($templatePath) || trim($templatePath) === '') {
        $templatePath = '/etc/seedbox/config/template.deluge.auth';
    }

    $password = pmssDelugeAuthReadLocalclientPassword($templatePath);
    return $password !== '' ? $password : 'db1f077e3ae178fad7608c327f2cd12dfe63ca67';
}

/**
 * Ensure Deluge uses a per-user service credential (not the shared template token).
 */
function pmssEnsureDelugeServicePassword(string $username): string
{
    $authPath = pmssDelugeAuthPath($username);
    $currentPassword = pmssDelugeAuthReadLocalclientPassword($authPath);
    $templatePassword = pmssDelugeTemplateLocalclientPassword();

    if ($currentPassword !== '' && $currentPassword !== $templatePassword) {
        return $currentPassword;
    }

    $newPassword = pmssDelugeServicePasswordGenerate();
    return pmssDelugeAuthWriteLocalclientPassword($authPath, $newPassword) ? $newPassword : $currentPassword;
}

/**
 * Read the active shadow hash for a managed user.
 *
 * Returns an empty string when the entry is missing, locked, or unreadable.
 */
function pmssUserShadowPasswordHashRead(string $username, string $shadowPath = '/etc/shadow'): string
{
    $username = trim($username);
    if (($parts = pmssColonRecordFieldsLookup($shadowPath, $username, 2)) === null) {
        return '';
    }
    $passwordHash = trim((string) $parts[1]);
    if (
        $passwordHash === ''
        || $passwordHash === '*'
        || $passwordHash === '!'
        || strpos($passwordHash, '!') === 0
        || strpos($passwordHash, ':') !== false
        || preg_match('/[\r\n\0]/', $passwordHash) === 1
    ) {
        return '';
    }

    return $passwordHash;
}

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
    $homeRoot = getenv('PMSS_HOME_DIR');
    if (!is_string($homeRoot) || trim($homeRoot) === '') {
        $homeRoot = '/home';
    }

    $passwordHash = pmssUserShadowPasswordHashRead($username, $shadowPath);
    if ($passwordHash === '') {
        return false;
    }

    if ($htpasswdPath === null || trim($htpasswdPath) === '') {
        $htpasswdPath = rtrim($homeRoot, '/').'/'.$username.'/.lighttpd/.htpasswd';
    }

    return pmssUserHtpasswdHashWrite($htpasswdPath, $username, $passwordHash, $username);
}

// Deluge password sync intentionally omitted: Deluge daemon auth stores passwords
// in plaintext (all versions <= 2.1.1). Syncing the account password here would
// expose it in a readable file under the user's home directory. Deluge service
// credentials are handled separately via pmssEnsureDelugeServicePassword().

/**
 * Update qBittorrent config with new password hash.
 *
 * @param string $username Username to update
 * @param string $password New plaintext password
 * @return bool True on success, false if config doesn't exist
 */
function pmssUpdateQbittorrentPassword(string $username, string $password): bool
{
    return pmssQbittorrentApplyPassword($username, $password);
}

/**
 * Restart user services gracefully after password change.
 *
 * @param string $username Username whose services to restart
 * @param bool $delugeUpdated Whether Deluge password was updated
 * @param bool $qbittorrentUpdated Whether qBittorrent password was updated
 */
function pmssRestartTorrentServicesAfterPasswordChange(string $username, bool $delugeUpdated, bool $qbittorrentUpdated): void
{
    // Kill torrent daemons gracefully - watchdog cron will restart them.
    foreach (['deluged' => $delugeUpdated, 'qbittorrent-nox' => $qbittorrentUpdated] as $daemon => $enabled) {
        if (!$enabled) {
            continue;
        }
        shell_exec(sprintf('killall -u %s -TERM %s 2>/dev/null', escapeshellarg($username), $daemon));
    }
}
