#!/usr/bin/php
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
 */
$usage = 'Usage: changePw.php USERNAME [PASSWORD]';
if (empty($argv[1])) die($usage . "\nPassword is optional - random one will be generated if it's empty\n");
if (!file_exists("/home/{$argv[1]}") or
    !is_dir("/home/{$argv[1]}")) die("\t**** USER NOT FOUND ****\n\n");

$username = $argv[1];
if (empty($argv[2])) $password = generatePassword();
    else $password = $argv[2];
    
echo "\t *******  {$username}     new password:   {$password} \n";

shell_exec('echo "' . $password . '\n' . $password . '"|passwd ' . $username);

$htpasswdFile = "/home/{$username}/.lighttpd/.htpasswd";

if (file_exists("/home/{$username}/.lighttpd/.htpasswd")) $htpasswdCommand = 'htpasswd -b -m';
    else $htpasswdCommand = 'htpasswd -c -b -m';

shell_exec("{$htpasswdCommand} {$htpasswdFile} {$username} {$password}");     // Create http password
passthru("chown {$username}.{$username} /home/{$username}/.lighttpd/.htpasswd");








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
    $salts = file_get_contents('/etc/hostname');
    $salts .= file_get_contents('/etc/debian_version');
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
