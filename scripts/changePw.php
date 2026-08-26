#!/usr/bin/env php
<?php
/**
 * Update a tenant's system and HTTP credentials.
 *
 * - Accepts an optional password argument; otherwise generates one using the
 *   legacy seed algorithm so existing automation keeps predictable entropy.
 * - Invokes `passwd` for the Unix account and rewrites the lighttpd htpasswd
 *   entry (creating the file when missing).
 * - Passwords are echoed to the operator; call sites must ensure the terminal
 *   history is handled appropriately.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
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

// Feed the password via stdin to passwd using printf; quote arguments to avoid
// injection even when passwords contain special characters.
$pwPayload = $password."\n".$password."\n";
$cmd = sprintf(
    "printf '%%s' %s | passwd %s",
    escapeshellarg($pwPayload),
    escapeshellarg($username)
);
$passwdOutput = [];
$passwdReturnCode = 0;
exec($cmd.' 2>&1', $passwdOutput, $passwdReturnCode);
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

$htpasswdCommand = is_file($htpasswdFile) ? 'htpasswd -b -m' : 'htpasswd -c -b -m';

$htpasswdOutput = [];
$htpasswdReturnCode = 0;
exec(sprintf(
    '%s %s %s %s 2>&1',
    $htpasswdCommand,
    escapeshellarg($htpasswdFile),
    escapeshellarg($username),
    escapeshellarg($password)
), $htpasswdOutput, $htpasswdReturnCode);
if ($htpasswdReturnCode !== 0 || !is_file($htpasswdFile)) {
    fwrite(STDERR, "htpasswd update failed for {$username}; aborting credential sync\n");
    if ($htpasswdOutput !== []) {
        fwrite(STDERR, implode("\n", $htpasswdOutput)."\n");
    }
    if ($jsonlOutput) {
        pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, null);
    }
    exit(1);
}

$chownOutput = [];
$chownReturnCode = 0;
exec(sprintf(
    'chown %s %s 2>&1',
    escapeshellarg($username.':'.$username),
    escapeshellarg($htpasswdFile)
), $chownOutput, $chownReturnCode);
if ($chownReturnCode !== 0) {
    fwrite(STDERR, "htpasswd ownership update failed for {$username}; aborting credential sync\n");
    if ($chownOutput !== []) {
        fwrite(STDERR, implode("\n", $chownOutput)."\n");
    }
    if ($jsonlOutput) {
        pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, null);
    }
    exit(1);
}

// Sync password to qBittorrent if installed.
// Deluge is intentionally excluded: its auth file stores passwords in plaintext,
// making account password sync a security risk (see GH#211).
$qbittorrentUpdated = pmssUpdateQbittorrentPassword($username, $password);

// qBittorrent writes its in-memory config during graceful shutdown. Kill it
// after the hash write so stale settings cannot restore the previous password;
// watchdog cron will restart it.
if ($qbittorrentUpdated) {
    shell_exec(sprintf('killall -u %s -KILL %s 2>/dev/null', escapeshellarg($username), 'qbittorrent-nox'));
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
