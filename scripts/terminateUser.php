#!/usr/bin/php
<?php
$continue = '-';

$usage = "Usage: terminateUser.php USERNAME\n";
if (empty($argv[1]) ) die($usage . "\n");
if (isset($argv[2]) &&
    $argv[1] == '--confirm') {
    
    $continue='Y';
    $username = $argv[2];

} else $username = $argv[1];
$username = trim($username);

if (empty($username)) die("No username given\n");

if (!file_exists("/home/{$username}") or
    !is_dir("/home/{$username}")) die("\t**** USER NOT FOUND ****\n\n");

echo "\n\t *** TERMINATE USER:  {$username} *** \n";

while (!in_array($continue, array('Y', 'N'))) {
    echo "Do you want to continue (Y/N)? ";
    $continue = strtoupper( trim(FGETS(STDIN)) );
}
if ($continue == 'N') die("\n");

echo "Terminating user {$username}\n";
passthru("killall -9 -u {$username}");

echo "\nRunning processes by user:\n";
passthru("ps aux|grep {$username}"); // Informal purposes, if tasks running, ie. ftp ;)

sleep(3);   // Allow time for rTorrent to die

passthru("killall -9 -u {$username}");  // Sometimes things just don't dieee!

// Clean up reserved rTorrent ports before removing the home directory
$portFile = "/home/{$username}/.rtorrent.rc";
$ports = [];
if (file_exists($portFile)) {
    $configLines = file($portFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($configLines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] == '#') continue;
        if (preg_match('/^scgi_port\s*=\s*(?:[^:]*:)?(\d+)/i', $line, $m)) {
            $ports['scgi'] = (int)$m[1];
        } elseif (preg_match('/dht.*port.*=\s*(\d+)/i', $line, $m)) {
            $ports['dht'] = (int)$m[1];
        } elseif (preg_match('/port_range.*=\s*(\d+)/i', $line, $m)) {
            $ports['listen'] = (int)$m[1];
        }
    }
    $portsBase = '/var/lib/pmss/ports';
    foreach ($ports as $type => $port) {
        $filePath = "$portsBase/{$type}/{$port}";
        if (file_exists($filePath)) {
            unlink($filePath);
            $dir = dirname($filePath);
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) rmdir($dir);
        }
    }
    if (is_dir($portsBase) && count(glob($portsBase . '/*')) === 0) {
        rmdir($portsBase);
    }
}

echo "\nDeleting user, user data and HTTP password:\n";
passthru("userdel {$username}; cd /home; rm -rf {$username}");
//passthru("htpasswd -D /etc/lighttpd/.htpasswd {$username}");
passthru('/scripts/util/createNginxConfig.php');   // Reconfig nginx
passthru('/etc/init.d/nginx restart');
passthru("userdel {$username}; groupdel {$username};"); // If during first attempt still some process running.
                                        //Make sure by attempting again FURTHER group needs to be deleted as well
passthru("rm -rf /var/run/screen/S-{$username}");
passthru("rm -rf /home/{$username} /etc/nginx/users/{$username}");
passthru("/scripts/util/portManager.php release {$username} lighttpd");
unlink("/etc/nginx/users/{$username}");

// If attemps 1 and 2 failed ...
passthru("killall -9 -u {$username}");
passthru("userdel {$username}; groupdel {$username};");

// We don't need setup network here because ... well that chain is not going to get any additional data anymore

echo "\n## Done. User terminated.\n";
