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
$username = $argv[1] ?? '';
$password = $argv[2] ?? '';

$usage = 'Usage: changePw.php USERNAME [PASSWORD]';
if ($username === '') {
    die($usage . "\nPassword is optional - random one will be generated if it's empty\n");
}

require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/homeMount.php';
require_once __DIR__.'/lib/user/passwords.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Changing
// a user's password when /home is unavailable would fail or write to stale paths.
pmssRequireHomeMounted('changePw.php');

$username = pmssRequireCliUsername(
    $username,
    'password',
    "Invalid username: %s\n",
    'Rejected username due to validation failure in changePw.php'
);

if (!file_exists("/home/{$username}") or
    !is_dir("/home/{$username}")) die("\t**** USER NOT FOUND ****\n\n");

if ($password === '') {
    $password = generatePassword();
}
    
echo "\t *******  {$username}     new password:   {$password} \n";

// Feed the password via stdin to passwd using printf; quote arguments to avoid
// injection even when passwords contain special characters.
$pwPayload = $password."\n".$password."\n";
$cmd = sprintf(
    'printf %s | passwd %s',
    escapeshellarg($pwPayload),
    escapeshellarg($username)
);
shell_exec($cmd);

$htpasswdFile = "/home/{$username}/.lighttpd/.htpasswd";

$htpasswdCommand = file_exists($htpasswdFile) ? 'htpasswd -b -m' : 'htpasswd -c -b -m';

shell_exec(sprintf(
    '%s %s %s %s',
    $htpasswdCommand,
    escapeshellarg($htpasswdFile),
    escapeshellarg($username),
    escapeshellarg($password)
));     // Create http password
passthru(sprintf(
    'chown %s /home/%s/.lighttpd/.htpasswd',
    escapeshellarg($username.':'.$username),
    escapeshellarg($username)
));

// Sync password to qBittorrent if installed.
// Deluge is intentionally excluded: its auth file stores passwords in plaintext,
// making account password sync a security risk (see GH#211).
$qbittorrentUpdated = pmssUpdateQbittorrentPassword($username, $password);

// Restart services if passwords were updated
pmssRestartTorrentServicesAfterPasswordChange($username, false, $qbittorrentUpdated);

if ($qbittorrentUpdated) {
    echo "\t *******  qBittorrent password updated\n";
}






function generatePassword(): string
{
    $legacySeed = legacyPasswordSeed();
    $prefix = substr($legacySeed, 0, 2);
    $suffix = substr($legacySeed, -2);

    $middleLength = random_int(4, 8);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
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
