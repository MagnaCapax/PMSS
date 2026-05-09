<?php
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../lighttpd/userConfigApply.php';
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
 * Resolve the Deluge Web UI config path for a user.
 */
function pmssDelugeWebConfPath(string $username): string
{
    $homeRoot = getenv('PMSS_HOME_DIR');
    if (!is_string($homeRoot) || trim($homeRoot) === '') {
        $homeRoot = '/home';
    }

    return rtrim($homeRoot, '/').'/'.$username.'/.config/deluge/web.conf';
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
 * Write the Web UI hash that corresponds to a Deluge service password.
 */
function pmssDelugeWebPasswordWrite(string $webConfPath, string $password, string $owner): bool
{
    if ($password === '' || !pmssUserFilePathIsSafe($webConfPath) || !is_file($webConfPath) || is_link($webConfPath)) {
        return false;
    }

    $parsed = pmssDelugeReadWebConf($webConfPath);
    if (!is_array($parsed) || !is_array($parsed['meta'] ?? null) || !is_array($parsed['config'] ?? null)) {
        return false;
    }

    $config = $parsed['config'];
    $salt = $config['pwd_salt'] ?? '';
    if (!is_string($salt) || strlen($salt) !== 40 || !ctype_xdigit($salt)) {
        $salt = bin2hex(random_bytes(20));
    }

    $hash = sha1($salt.$password);
    if (($config['pwd_salt'] ?? '') === $salt && ($config['pwd_sha1'] ?? '') === $hash) {
        return true;
    }

    $config['pwd_salt'] = $salt;
    $config['pwd_sha1'] = $hash;
    pmssDelugeNormalizeEmptySessionsObject($config);

    return pmssDelugeWriteWebConf($webConfPath, $parsed['meta'], $config, $owner);
}

/**
 * Apply one Deluge service password to both daemon auth and web.conf.
 */
function pmssDelugeServicePasswordApply(string $username, string $password): bool
{
    if ($password === '') {
        return false;
    }

    $authPath = pmssDelugeAuthPath($username);
    $webConfPath = pmssDelugeWebConfPath($username);
    if (!pmssUserFilePathIsSafe($authPath) || !pmssUserFilePathIsSafe($webConfPath)) {
        return false;
    }

    $originalWebConfig = pmssDelugeReadWebConf($webConfPath);
    if (!is_array($originalWebConfig) || !is_array($originalWebConfig['meta'] ?? null) || !is_array($originalWebConfig['config'] ?? null)) {
        return false;
    }

    if (!pmssDelugeWebPasswordWrite($webConfPath, $password, $username)) {
        return false;
    }

    if (pmssDelugeAuthWriteLocalclientPassword($authPath, $password)) {
        return true;
    }

    pmssDelugeWriteWebConf($webConfPath, $originalWebConfig['meta'], $originalWebConfig['config'], $username);
    return false;
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
        if (!pmssDelugeWebPasswordWrite(pmssDelugeWebConfPath($username), $currentPassword, $username) && function_exists('pmssLogStatus')) {
            pmssLogStatus('WARN', 'Deluge Web UI password hash sync failed', 1);
        }
        return $currentPassword;
    }

    $newPassword = pmssDelugeServicePasswordGenerate();
    if (!pmssDelugeServicePasswordApply($username, $newPassword) && function_exists('pmssLogStatus')) {
        pmssLogStatus('WARN', 'Deluge service credential sync failed', 1);
        return $currentPassword;
    }

    return $newPassword;
}

/**
 * Rotate the Deluge service credential and keep daemon/web auth in sync.
 */
function pmssDelugeServicePasswordRotate(string $username): string
{
    $newPassword = pmssDelugeServicePasswordGenerate();
    if (!pmssDelugeServicePasswordApply($username, $newPassword)) {
        if (function_exists('pmssLogStatus')) {
            pmssLogStatus('WARN', 'Deluge service credential rotation failed', 1);
        }
        return '';
    }

    return $newPassword;
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

// Deluge account-password sync intentionally omitted: Deluge daemon auth stores
// service passwords in plaintext (all versions <= 2.1.1). Reusing the account
// password here would expose it in a readable file under the user's home
// directory. Deluge service credentials are managed separately and mirrored
// into web.conf via pmssEnsureDelugeServicePassword()/pmssDelugeServicePasswordRotate().

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
