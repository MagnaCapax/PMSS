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

// Deluge password sync intentionally omitted: Deluge daemon auth stores passwords
// in plaintext (all versions <= 2.1.1). Syncing the account password here would
// expose it in a readable file under the user's home directory. See GH#211 for
// the planned fix (separate random password shown to user). -- 2026-02-11

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
    if ($delugeUpdated) {
        // Kill deluged gracefully - watchdog cron will restart it
        shell_exec(sprintf('killall -u %s -TERM deluged 2>/dev/null', escapeshellarg($username)));
    }

    if ($qbittorrentUpdated) {
        // Kill qbittorrent-nox gracefully - watchdog cron will restart it
        shell_exec(sprintf('killall -u %s -TERM qbittorrent-nox 2>/dev/null', escapeshellarg($username)));
    }
}
