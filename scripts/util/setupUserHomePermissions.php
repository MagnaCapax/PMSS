#!/usr/bin/php
<?php
$usage = "Usage: setupUserHomePermissions.php USERNAME\n";
if (empty($argv[1])) die($usage);

$user = array('name' => $argv[1]);

if (!file_exists("/home/{$user['name']}")) die("User does not exist\n");

//chdir("/home/{$user['name']}");
//passthru("chmod 777 ./ -R ; chmod 771 .");
shell_exec('chown root.root /home/' . $user['name'] . '/.rtorrent.rc');
shell_exec('chmod 775 /home/' . $user['name'] . '/.rtorrent.rc');
shell_exec('chown root.root /home/' . $user['name'] . '/www/rutorrent/conf/*');
shell_exec('chmod 775 /home/' . $user['name'] . '/www/rutorrent/conf/*');

`chmod 750 /home/{$user['name']}/.lighttpd/custom.d`;

