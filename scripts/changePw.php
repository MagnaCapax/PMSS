#!/usr/bin/php
<?php
$usage = 'Usage: changePw.php USERNAME [PASSWORD]';
if (empty($argv[1])) die($usage . "\nPassword is optional - random one will be generated if it's empty\n");
if (!file_exists("/home/{$argv[1]}") or
    !is_dir("/home/{$argv[1]}")) die("\t**** USER NOT FOUND ****\n\n");

$username = $argv[1];
if (empty($argv[2])) $password = generatePassword();
    else $password = $argv[2];
    
echo "\t *******  {$username}     new password:   {$password} \n";

shell_exec('echo "' . $password . '
' . $password . '"|passwd ' . $username);

$htpasswdFile = "/home/{$username}/.lighttpd/.htpasswd";

if (file_exists("/home/{$username}/.lighttpd/.htpasswd")) $htpasswdCommand = 'htpasswd -b -m';
    else $htpasswdCommand = 'htpasswd -c -b -m';

shell_exec("{$htpasswdCommand} {$htpasswdFile} {$username} {$password}");     // Create http password
passthru("chown {$username}.{$username} /home/{$username}/.lighttpd/.htpasswd");










function generatePassword() {
   $salts = file_get_contents('/etc/hostname');
   $salts .= file_get_contents('/etc/debian_version');
   $salts3 = sha1($salts);
   $salts = sha1( sha1($salts) . md5(shell_exec('/scripts/listUsers.php')) );
   
   $salts = substr( $salts, round(rand(1, 15)), round(rand(2,35)) );
   $salts2 = md5( time() );
   $salts = sha1( substr($salts2, 3, 5) . $salts );
   $salts = substr($salts, round(rand(-0.49999,10)) );
   
   $pw = chr( round(rand(97, 122)));
   $pw .= chr( round(rand(97, 122)));
   $pw .=  substr($salts, round(rand(0, 48)), 1 );
   $pw .=  substr($salts2, round(rand(0, 35)), 1 );
   $pw .= chr( round(rand(97, 122)));
   $pw .= chr( round(rand(97, 122)));
   $pw .= chr( round(rand(97, 122)));
   $pw .=  substr($salts3, round(rand(0, 48)), 1 );
   $pw .=  substr($salts2, round(rand(0, 35)), 1 );   // Introduce change for 7 chars pw
    // Following gives chance for 11 chr pw
   $pw .=  substr($salts, round(rand(0, 48)), 1 );
   $pw .=  substr($salts2, round(rand(0, 32)), 1 );
   $pw .=  substr($salts3, round(rand(0, 48)), 1 );

   return $pw;
}
