#!/usr/bin/php
<?php
$usage = 'Usage: recreateUser.php USERNAME MAX_RTORRENT_MEMORY_IN_MB DISK_QUOTA_IN_GB';
if (empty($argv[1]) or
    empty($argv[2]) or
    empty($argv[3]) ) die($usage . "\n");
    
$user = array(
    'name'      => $argv[1],    
    'memory'    => $argv[2],
    'quota'     => $argv[3]
);
if (file_exists('/home/backup-' . $user['name'])) die("Backup directory already exists - please clear this first. \n");

passthru("killall -9 -u {$user['name']}");

if (file_exists('/home/' . $user['name'])) {}
    passthru("mv /home/{$user['name']} /home/backup-{$user['name']}");
    
passthru("cp -Rp /etc/skel /home/{$user['name']}; chown {$user['name']}.{$user['name']} /home/{$user['name']} -R");
passthru("cp -Rp /etc/skel/.lighttpd /home/{$user['name']}/; chown {$user['name']}.{$user['name']} /home/{$user['name']}/.lighttpd -R");
passthru("/scripts/util/userConfig.php {$user['name']} {$user['memory']} {$user['quota']}");

passthru("/scripts/util/setupUserHomePermissions.php {$user['name']}"); #TODO Inspect and remove?


passthru("/scripts/util/createNginxConfig.php");
passthru("/scripts/util/configureLighttpd.php {$user['name']}");

passthru("/scripts/util/userPermissions.php {$user['name']}");

if (file_exists("/home/backup-{$user['name']}") ) {
    if (file_exists("/home/backup-{$user['name']}/data"))
        passthru("mv /home/backup-{$user['name']}/data/* /home/{$user['name']}/data/");
        
    if (file_exists("/home/backup-{$user['name']}/session"))
        passthru("mv /home/backup-{$user['name']}/session/* /home/{$user['name']}/session/");
	
    if (file_exists("/home/backup-{$user['name']}/.lighttpd"))
        passthru("cp /home/backup-{$user['name']}/.lighttpd/.htpasswd /home/{$user['name']}/.lighttpd/");
        
    echo "You should considering removing backup dir:\nrm -rf /home/backup-{$user['name']}\n";

}
