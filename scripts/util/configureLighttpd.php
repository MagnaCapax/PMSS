#!/usr/bin/php
<?php
// Configure all user lighttpd instances idempotently

//echo date('Y-m-d H:i:s') . ': Re-creating Lighttpd configuration' . "\n";

function pmssDetectDebianVersion(): int
{
    $path = getenv('PMSS_OS_RELEASE_PATH');
    if ($path === false || $path === '') {
        $path = '/etc/os-release';
    }
    if (!is_readable($path)) {
        return 0;
    }
    $data = @file_get_contents($path);
    if ($data === false) {
        return 0;
    }
    if (preg_match('/^VERSION_ID=\"?([0-9]+)/m', $data, $matches)) {
        return (int) $matches[1];
    }
    return 0;
}

function pmssNormalizeCompressionConfig(string $template, int $distroVersion): string
{
    // Debian 11/12 ship mod_deflate; compress.* triggers deprecation on bookworm.
    if ($distroVersion < 11) {
        return $template;
    }

    return str_replace(
        array('compress.cache-dir', 'compress.filetype', '"mod_compress"'),
        array('deflate.cache-dir', 'deflate.mimetypes', '"mod_deflate"'),
        $template
    );
}

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
$template = pmssNormalizeCompressionConfig($template, pmssDetectDebianVersion());
$userConfig = '';

foreach($users AS $thisUser) {
    #TODO(user-logs): log per-user lighttpd config/port allocation and file operations to /var/log/pmss/user-<username>.log
    if (!file_exists("/home/{$thisUser}/.rtorrent.rc")) continue;   // Suspended or not torrent user
    $portFile = "{$portsDirectory}/lighttpd-{$thisUser}";
    if (file_exists($portFile)) {
        $serverPort = (int) file_get_contents($portFile);
    } else {
        // Allocate a unique port using portManager utility
        $serverPort = (int) trim(shell_exec("/scripts/util/portManager.php assign {$thisUser} lighttpd"));
    }
    
    
    # No lighttpd files yet? No matter, let's add them!
    if (!file_exists("/home/{$thisUser}/.lighttpd")) {
        passthru("cp -Rp /etc/skel/.lighttpd /home/{$thisUser}/");
        passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/.lighttpd -R");
        passthru("chmod 751 /home/{$thisUser}/.lighttpd -R");
    }
    if (!file_exists("/home/{$thisUser}/.lighttpd/php.ini")) {
        passthru("cp -p /etc/skel/.lighttpd/php.ini /home/{$thisUser}/.lighttpd/php.ini");
        passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/.lighttpd/php.ini -R");
        passthru("chmod 751 /home/{$thisUser}/.lighttpd/php.ini -R");
    }
    if (!file_exists("/home/{$thisUser}/www/public")) {
        passthru("mkdir /home/{$thisUser}/www/public");
        passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/www/public");
        passthru("chmod 751 /home/{$thisUser}/www/public -R");
    }
    if (!file_exists("/home/{$thisUser}/.lighttpd/custom.d")) {
        passthru("mkdir /home/{$thisUser}/.lighttpd/custom.d");
        passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/.lighttpd/custom.d");
        passthru("chmod 750 /home/{$thisUser}/.lighttpd/custom.d");
    }
    $uploadDir = "/home/{$thisUser}/.lighttpd/upload";
    if (!is_dir($uploadDir)) {
        passthru("mkdir -p {$uploadDir}");
        passthru("chown {$thisUser}:{$thisUser} {$uploadDir}");
        passthru("chmod 751 {$uploadDir}");
    }
    $compressDir = "/home/{$thisUser}/.lighttpd/compress";
    if (!is_dir($compressDir)) {
        passthru("mkdir -p {$compressDir}");
        passthru("chown {$thisUser}:{$thisUser} {$compressDir}");
        passthru("chmod 751 {$compressDir}");
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
    passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/.lighttpd.conf; chmod 741 /home/{$thisUser}/.lighttpd.conf");   // Set permissions

    
}
