#!/usr/bin/php
<?php
// Configure all user lighttpd instances idempotently

//echo date('Y-m-d H:i:s') . ': Re-creating Lighttpd configuration' . "\n";

$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
if (count($users) == 0) die("No users setup - nothing to do\n");

if (isset($argv[1]) && !empty($argv[1])) {
    $argUsername = strtolower($argv[1]);
    if (in_array($argUsername, $users, true)) {
        $users = array($argUsername);   // Only do this user
    } else die("Username not found\n");

}

$portsDirectory = '/etc/seedbox/runtime/ports';
if (!file_exists($portsDirectory))  {
    mkdir($portsDirectory);
    passthru("chmod 600 {$portsDirectory}");
}
if (!file_exists('/root/backups')) `mkdir /root/backups`;
$template = file_get_contents("/etc/seedbox/config/template.lighttpd");
$userConfig = '';

foreach($users AS $thisUser) {
    if (!file_exists("/home/{$thisUser}/.rtorrent.rc")) continue;   // Suspended or not torrent user
    $portFile = "{$portsDirectory}/lighttpd-{$thisUser}";
    if (file_exists($portFile)) {
        $serverPort = (int) file_get_contents($portFile);
    } else {
        #TODO command line script to set the port
		#TODO and check that no one else uses the same, doh!
        $serverPort = (int) rand(2000,38000);
        file_put_contents($portFile, $serverPort);
    }
    
    
    # No lighttpd files yet? No matter, let's add them!
    if (!file_exists("/home/{$thisUser}/.lighttpd")) {
        passthru("cp -Rp /etc/skel/.lighttpd /home/{$thisUser}/");
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/.lighttpd -R");
        passthru("chmod 751 /home/{$thisUser}/.lighttpd -R");
    }
    if (!file_exists("/home/{$thisUser}/.lighttpd/php.ini")) {
        passthru("cp -p /etc/skel/.lighttpd/php.ini /home/{$thisUser}/.lighttpd/php.ini");
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/.lighttpd/php.ini -R");
        passthru("chmod 751 /home/{$thisUser}/.lighttpd/php.ini -R");
    }
    if (!file_exists("/home/{$thisUser}/www/public")) {
        passthru("mkdir /home/{$thisUser}/www/public");
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/public");
        passthru("chmod 751 /home/{$thisUser}/www/public -R");
    }
    if (!file_exists("/home/{$thisUser}/.lighttpd/custom.d")) {
        passthru("mkdir /home/{$thisUser}/.lighttpd/custom.d");
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/.lighttpd/custom.d");
        passthru("chmod 750 /home/{$thisUser}/.lighttpd/custom.d");
    }
    
    //Backup old one
/*  Sometimes annoyingly many backups!
    if (file_exists("/home/{$thisUser}/.lighttpd.conf")) {
        $backupName = date('Ymd_Hi') . "-lighttpd-{$thisUser}.conf";
        passthru("cp /home/{$thisUser}/.lighttpd.conf /root/backups/{$backupName}");
        passthru("cp -p /home/{$thisUser}/.lighttpd.conf /home/{$thisUser}/.{$backupName};");   // Make a backup for the user too, preserving permissions
    }
*/

    
    // Rclone port
    $rclonePort = (int) trim( @file_get_contents("/home/{$thisUser}/.rclonePort") );
    if ($rclonePort < 1024 or $rclonePort > 65500) {
        $rclonePort = (int) round( rand(1500,65500) );
        file_put_contents("/home/{$thisUser}/.rclonePort", $rclonePort);
    }

    // qBittorrent port
    $qbittorrentPort = (int) trim( @file_get_contents("/home/{$thisUser}/.qbittorrentPort") );
    if ($qbittorrentPort < 1024 or $qbittorrentPort > 65500) {
        $qbittorrentPort = (int) round( rand(1500,65500) );
        file_put_contents("/home/{$thisUser}/.qbittorrentPort", $rclonePort);
    }
    
    $thisUserConfig = str_replace(array("##username", "##serverPort", "##rclonePort", "##qbittorrentPort"), array($thisUser, $serverPort, $rclonePort, $qbittorrentPort), $template);
    file_put_contents("/home/{$thisUser}/.lighttpd.conf", $thisUserConfig);
    passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/.lighttpd.conf; chmod 741 /home/{$thisUser}/.lighttpd.conf");   // Set permissions

    
}

