<?php
/**
 * Password synchronization helpers for torrent clients.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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

/**
 * Update Deluge auth file with new password.
 *
 * Replaces the template 'localclient' entry with the actual username and password,
 * or updates an existing entry for the username.
 *
 * @param string $username Username to update
 * @param string $password New plaintext password
 * @return bool True on success, false if auth file doesn't exist
 */
function pmssUpdateDelugePassword(string $username, string $password): bool
{
    $authFile = "/home/{$username}/.config/deluge/auth";
    if (!file_exists($authFile)) {
        return false;
    }

    $lines = file($authFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    $updated = false;
    foreach ($lines as $i => $line) {
        $parts = explode(':', $line, 3);
        if (count($parts) === 3) {
            // Update existing username entry or replace template localclient
            if ($parts[0] === $username || $parts[0] === 'localclient') {
                $lines[$i] = $username . ':' . $password . ':' . $parts[2];
                $updated = true;
                break;
            }
        }
    }

    if (!$updated) {
        return false;
    }

    return file_put_contents($authFile, implode("\n", $lines) . "\n") !== false;
}

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
    if (!file_exists($configFile)) {
        return false;
    }

    $config = file_get_contents($configFile);
    if ($config === false) {
        return false;
    }

    $passwordHash = pmssGenerateQbittorrentPasswordHash($password);
    $pattern = '/^WebUI\\\\Password_PBKDF2=.*/m';
    $replacement = 'WebUI\\Password_PBKDF2=' . $passwordHash;

    if (preg_match($pattern, $config)) {
        $newConfig = preg_replace($pattern, $replacement, $config);
    } else {
        // If password line doesn't exist, add it under [Preferences]
        $pattern = '/(\[Preferences\][^\[]*)/s';
        $replacement = '$1' . $replacement . "\n";
        $newConfig = preg_replace($pattern, $replacement, $config, 1);
    }

    if ($newConfig === null || $newConfig === $config) {
        return false;
    }

    return file_put_contents($configFile, $newConfig) !== false;
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
    if ($delugeUpdated) {
        // Kill deluged gracefully - watchdog cron will restart it
        shell_exec(sprintf('killall -u %s -TERM deluged 2>/dev/null', escapeshellarg($username)));
    }

    if ($qbittorrentUpdated) {
        // Kill qbittorrent-nox gracefully - watchdog cron will restart it
        shell_exec(sprintf('killall -u %s -TERM qbittorrent-nox 2>/dev/null', escapeshellarg($username)));
    }
}
