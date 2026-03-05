<?php
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

    $written = @file_put_contents($authPath, implode("\n", $lines)."\n");
    if ($written === false) {
        return false;
    }

    @chmod($authPath, 0600);
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
 * Generate qBittorrent PBKDF2 password hash.
 *
 * @param string $password Plaintext password
 * @return string Formatted hash string for qBittorrent.conf
 */
function pmssGenerateQbittorrentPasswordHash(string $password): string
{
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha512', $password, $salt, 100000, 64, true);
    return '@ByteArray(' . base64_encode($salt) . ':' . base64_encode($hash) . ')';
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
    $configFile = "/home/{$username}/.config/qBittorrent/qBittorrent.conf";
    if (!file_exists($configFile) || ($config = file_get_contents($configFile)) === false) {
        return false;
    }

    $passwordHash = pmssGenerateQbittorrentPasswordHash($password);
    $replacement = 'WebUI\\Password_PBKDF2=' . $passwordHash;

    if (preg_match('/^WebUI\\\\Password_PBKDF2=.*/m', $config)) {
        $newConfig = preg_replace('/^WebUI\\\\Password_PBKDF2=.*/m', $replacement, $config);
    } else {
        // If password line doesn't exist, add it under [Preferences]
        $newConfig = preg_replace('/(\[Preferences\][^\[]*)/s', '$1' . $replacement . "\n", $config, 1);
    }

    return $newConfig !== null
        && $newConfig !== $config
        && file_put_contents($configFile, $newConfig) !== false;
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
