#!/usr/bin/env php
<?php
/**
 * Update a tenant's system and HTTP credentials.
 *
 * - Accepts an optional password argument; otherwise generates one using the
 *   legacy seed algorithm so existing automation keeps predictable entropy.
 * - Shares one bcrypt hash between shadow and htpasswd on Debian 11+;
 *   older or unknown releases retain the legacy password update.
 * - Passwords are echoed to the operator; call sites must ensure the terminal
 *   history is handled appropriately.
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
$jsonlOutput = false;
$arguments = [];
$parseOptions = true;
foreach (array_slice($argv, 1) as $token) {
    if ($parseOptions && $token === '--') {
        $parseOptions = false;
        continue;
    }
    if ($parseOptions && $token === '--jsonl') {
        $jsonlOutput = true;
        continue;
    }

    $arguments[] = $token;
}

$username = $arguments[0] ?? '';
$password = $arguments[1] ?? '';

$usage = 'Usage: changePw.php [--jsonl] USERNAME [PASSWORD]';
if ($username === '') {
    die($usage . "\nPassword is optional - random one will be generated if it's empty\n");
}

require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/homeMount.php';
require_once __DIR__.'/lib/user/qbittorrent.php';
require_once __DIR__.'/lib/torrentClients/identity.php';
require_once __DIR__.'/lib/user/passwordFile.php';
require_once __DIR__.'/lib/user/changePwCredential.php';
require_once __DIR__.'/lib/lighttpd/htpasswd.php';
require_once __DIR__.'/lib/user/userFilesystem.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Changing
// a user's password when /home is unavailable would fail or write to stale paths.
pmssRequireHomeMounted('changePw.php');

['username' => $username] = userFilesystem::requireCliUserHome($username, 'password', "Invalid username: %s\n", "\t**** USER NOT FOUND ****\n\n", 'Rejected username due to validation failure in changePw.php');

if ($password === '') {
    $password = generatePassword();
}

if (!$jsonlOutput) {
    echo "\t *******  {$username}     new password:   {$password} \n";
}

$useBcrypt = pmssChangePwBcryptSupported();
$passwdOutput = [];
[$passwdReturnCode, $passwordHash] = pmssChangePwShadowUpdate($username, $password, $useBcrypt, $passwdOutput);
if ($passwdReturnCode !== 0) {
    fwrite(STDERR, "passwd failed for {$username}; aborting credential sync\n");
    if ($passwdOutput !== []) {
        fwrite(STDERR, implode("\n", $passwdOutput)."\n");
    }
    if ($jsonlOutput) {
        pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, null, null);
    }
    exit(1);
}

$homeDir = '/home/'.$username;
$htpasswdFile = $homeDir.'/.lighttpd/.htpasswd';
$htpasswdDir = dirname($htpasswdFile);

if (!is_dir($htpasswdDir)) {
    fwrite(STDERR, "lighttpd credential directory missing for {$username}; aborting credential sync\n");
    if ($jsonlOutput) {
        pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, null, null);
    }
    exit(1);
}

$htpasswdOutput = [];
$htpasswdReturnCode = $useBcrypt
    ? (pmssUserHtpasswdHashWrite($htpasswdFile, $username, $passwordHash, $username) ? 0 : 1)
    : pmssUserHtpasswdPasswordUpdate($username, $password, $htpasswdFile, $htpasswdOutput);
if ($htpasswdReturnCode !== 0) {
    fwrite(STDERR, "htpasswd update failed for {$username}; aborting credential sync\n");
    if ($htpasswdOutput !== []) {
        fwrite(STDERR, implode("\n", $htpasswdOutput)."\n");
    }
    if ($jsonlOutput) {
        pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, null);
    }
    exit(1);
}

// A panel password change also invalidates vanilla Hermes browser sessions.
$hermesInstaller = $homeDir.'/bin/install-hermes';
if (is_file($hermesInstaller) && !is_link($hermesInstaller) && is_executable($hermesInstaller)) {
    exec(pmssBuildUserShellCommand($username, escapeshellarg($hermesInstaller).' rotate-session').' >/dev/null 2>&1');
}

// Sync password to qBittorrent if installed.
// Deluge is intentionally excluded: its auth file stores passwords in plaintext,
// making account password sync a security risk (see GH#211).
$qbittorrentUpdated = pmssUpdateQbittorrentPasswordAsUser($username, $password);

// qBittorrent writes its in-memory config during graceful shutdown. Kill it
// after the hash write so stale settings cannot restore the previous password;
// watchdog cron will restart it.
if ($qbittorrentUpdated) {
    pmssTorrentClientNativeStop($username, 'qbittorrent', 9);
}

if ($qbittorrentUpdated && !$jsonlOutput) {
    echo "\t *******  qBittorrent password updated\n";
}

if ($jsonlOutput) {
    pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, $qbittorrentUpdated);
}

function generatePassword(): string
{
    $legacySeed = legacyPasswordSeed();
    $prefix = substr($legacySeed, 0, 2);
    $suffix = substr($legacySeed, -2);

    $middleLength = random_int(4, 8);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
    $middle = '';
    $alphabetLength = strlen($alphabet) - 1;

    for ($i = 0; $i < $middleLength; $i++) {
        $middle .= $alphabet[random_int(0, $alphabetLength)];
    }

    return $prefix . $middle . $suffix;
}

/**
 * Reproduce the historic password entropy logic for prefix/suffix material.
 */
function legacyPasswordSeed(): string
{
    $salts = file_get_contents('/etc/hostname').file_get_contents('/etc/debian_version');
    $salts3 = sha1($salts);
    $salts = sha1(sha1($salts) . md5(shell_exec('/scripts/listUsers.php')));

    $salts = substr($salts, round(rand(1, 15)), round(rand(2, 35)));
    $salts2 = md5(time());
    $salts = sha1(substr($salts2, 3, 5) . $salts);
    $salts = substr($salts, round(rand(-0.49999, 10)));

    $pw = chr(round(rand(97, 122)));
    $pw .= chr(round(rand(97, 122)));
    $pw .= substr($salts, round(rand(0, 48)), 1);
    $pw .= substr($salts2, round(rand(0, 35)), 1);
    $pw .= chr(round(rand(97, 122)));
    $pw .= chr(round(rand(97, 122)));
    $pw .= chr(round(rand(97, 122)));
    $pw .= substr($salts3, round(rand(0, 48)), 1);
    $pw .= substr($salts2, round(rand(0, 35)), 1);
    $pw .= substr($salts, round(rand(0, 48)), 1);
    $pw .= substr($salts2, round(rand(0, 32)), 1);
    $pw .= substr($salts3, round(rand(0, 48)), 1);

    return $pw;
}

/**
 * Emit one machine-readable credential sync result.
 */
function pmssChangePwEmitJsonl(string $username, string $password, ?int $passwdReturnCode, ?int $htpasswdReturnCode, ?bool $qbittorrentUpdated): void
{
    $payload = [
        'username'            => $username,
        'new_credential'      => $password,
        'passwd_rc'           => $passwdReturnCode,
        'htpasswd_rc'         => $htpasswdReturnCode,
        'qbittorrent_updated' => $qbittorrentUpdated,
        'ts'                  => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    pmssJsonEmitPayload($payload, 'Failed to encode changePw JSONL payload.', JSON_UNESCAPED_SLASHES);
}
