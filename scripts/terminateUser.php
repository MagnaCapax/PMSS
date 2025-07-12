#!/usr/bin/php
<?php
/**
 * Terminate a seedbox user and clean up runtime configs.
 * Removes running processes, user account, ports and web server configs.
 *
 * Usage: terminateUser.php USERNAME [--confirm]
 */
define("PORTS_RUNTIME_DIR", "/etc/seedbox/runtime/ports");
define("PORTS_PERSISTENT_DIR", "/etc/seedbox/config/ports");
function clearUserPorts($user) {
    foreach ([PORTS_RUNTIME_DIR, PORTS_PERSISTENT_DIR] as $dir) {
        $file = "$dir/lighttpd-$user";
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

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

echo "\nDeleting user, user data and HTTP password:\n";
passthru("userdel {$username}; cd /home; rm -rf {$username}");
//passthru("htpasswd -D /etc/lighttpd/.htpasswd {$username}");
passthru('/scripts/util/createNginxConfig.php');   // Reconfig nginx
passthru('/etc/init.d/nginx restart');
passthru("userdel {$username}; groupdel {$username};"); // If during first attempt still some process running.
                                        //Make sure by attempting again FURTHER group needs to be deleted as well
passthru("rm -rf /var/run/screen/S-{$username}");

// Remove Lighttpd port assignments
clearUserPorts($username);
// Remove user home and nginx config

passthru("rm -rf /home/{$username} /etc/nginx/users/{$username}");

// If attemps 1 and 2 failed ...
passthru("killall -9 -u {$username}");
passthru("userdel {$username}; groupdel {$username};");

// We don't need setup network here because ... well that chain is not going to get any additional data anymore

echo "\n## Done. User terminated.\n";
