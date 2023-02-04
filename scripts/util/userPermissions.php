#!/usr/bin/php
<?php
# Set user folder permissions

$usage = 'Usage: ./userPermissions.php USERNAME';
if (empty($argv[1]) ) die('need user name. ' . $usage . "\n");
    
$thisUser = $argv[1];
if (!file_exists("/home/{$thisUser}")) die("User does not exist\n");
$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $thisUser) === false) die("No such user\n");

    `find /home/{$thisUser}/ -type d|xargs -n1 -d "\n" chmod 750`;
    `chmod 770 /home/{$thisUser}`;
    `chmod 640 /home/{$thisUser}/.viminfo`;
    `chmod 640 /home/{$thisUser}/.quota`;
    `chmod 640 /home/{$thisUser}/.profile`;
    `chmod 640 /home/{$thisUser}/.bash_history`;
    `chmod 640 /home/{$thisUser}/.bashrc`;
    `chmod 770 /home/{$thisUser}/.tmp`;
    `chmod 770 /home/{$thisUser}/.config -R`;
    `chmod 640 /home/{$thisUser}/.trafficData`;
    `chmod 644 /home/{$thisUser}/.rtorrent.rc`;
    `chmod 750 /home/{$thisUser}/watch -R`;
    `chmod 750 /home/{$thisUser}/session -R`;
    `chmod 750 /home/{$thisUser}/data -R`;
    `chmod 750 /home/{$thisUser}/www -R`;
    `chmod 750 /home/{$thisUser}/.*.php`;
    `chmod 775 /home/{$thisUser}/.lighttpd`;
    `chmod 754 /home/{$thisUser}/.lighttpd/.htpasswd`;
    `chmod 770 /home/{$thisUser}/.lighttpd/compress`;
    `chmod 770 /home/{$thisUser}/.lighttpd/upload`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/.lighttpd/.htpasswd`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/.lighttpd/ -R`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/ -R`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings/retrackers.dat`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/share/users/{$thisUser}`;
    `chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/torrents`;
    `chown root.root /home/{$thisUser}/.rtorrent.rc`;
    `chown root.root /home/{$thisUser}/www/rutorrent/conf/config.php`;
    `chmod 754 /home/{$thisUser}/www/rutorrent/conf/config.php`;
    `chmod 750 /home/{$thisUser}/.irssi`;
    `chmod 750 /home/{$thisUser}/.sync`;
    (file_exists("/home/{$thisUser}/.ssh") ? `chmod 750 /home/{$thisUser}/.ssh` : '');
