#!/usr/bin/php
<?php
$usage = 'Usage: addUser.php USERNAME PASSWORD MAX_RTORRENT_MEMORY_IN_MB DISK_QUOTA_IN_GB [trafficLimitGB]';
if (empty($argv[1]) or
    empty($argv[2]) or
    empty($argv[3]) or 
    empty($argv[4]) ) die($usage . "\n");
    
$user = array(
    'name'      => $argv[1],
    'password'  => $argv[2],
    'memory'    => $argv[3],
    'quota'     => $argv[4]    
);
if (isset($argv[5])) $user['trafficLimit'] = (int) $argv[5];
if ($user['password'] == 'rand') $user['password'] == '';
    
require_once 'lib/rtorrentConfig.php';
require_once 'lib/users.php';
$userDb = new users();

// Get our server hostname, and do some cleanup just to be safe
$hostname = trim( file_get_contents('/etc/hostname') );;
$hostname = str_replace(array("\n", "\r", "\t"), array('','',''), $hostname);


//Create the user
passthru("useradd --skel /etc/skel -m {$user['name']}");
passthru("/scripts/changePw.php {$user['name']} {$user['password']}");
passthru("usermod -U {$user['name']}");
passthru("usermod --expiredate 2100-01-01 {$user['name']}");
#passthru("usermod -G {$user['name']} www-data");

if (file_exists('/bin/bash'))	// Set shell
	passthru("chsh -s /bin/bash {$user['name']}");

// Then to DB :)
$userDb->addUser( $user['name'], array(
    'rtorrentRam' => $user['memory'],
    'quota' => $user['quota'],
    'quotaBurst' => round( $user['quota'] * 1.25 ),
    'rtorrentPort' => 0,    #TODO Choose port here and use that for the userConfig :)
    'suspended' => false
));

// Configure quota, rtorrent and ruTorrent.
passthru('/scripts/util/userConfig.php "' . $user['name'] . '" "' . $user['memory'] . '" "' . $user['quota'] . '"');

passthru("/scripts/util/configureLighttpd.php {$user['name']}");
passthru("/scripts/util/createNginxConfig.php");


#passthru("/scripts/util/recreateLighttpdConfig.php");
#passthru('/etc/init.d/lighttpd force-reload');      // restart lighttpd




$userHomedirPath = "/home/{$user['name']}";

// User data permissions
#chdir("/home/{$user['name']}");
#passthru("chmod 777 ./ -R ; chmod 771 ."); //; su {$argv[1]} -c \"screen -fa -d -m rtorrent\" ");
#shell_exec('chown root.root /home/' . $user['name'] . '/.rtorrent.rc');
#shell_exec('chmod 775 /home/' . $user['name'] . '/.rtorrent.rc');
#shell_exec('chown root.root /home/' . $user['name'] . '/www/rutorrent/conf/*');
#shell_exec('chmod 775 /home/' . $user['name'] . '/www/rutorrent/conf/*');


// Execute per server additional config for user creation IF there is any
if (file_exists('/etc/seedbox/modules/basic/addUser.php')) {
    writeLog('Initiating basic module for addUser.php');
    include '/etc/seedbox/modules/basic/addUser.php';
}

// Finally start rTorrent for the user
passthru('/scripts/startRtorrent ' . $user['name']);
passthru('/scripts/startLighttpd ' . $user['name']);
passthru('/etc/init.d/nginx restart');
passthru('/scripts/util/setupNetwork.php');

if (!empty($user['trafficLimit']) &&
    $user['trafficLimit'] > 0) {
    if (!file_exists("/etc/seedbox/runtime/trafficLimits")) mkdir("/etc/seedbox/runtime/trafficLimits");
    file_put_contents( "/etc/seedbox/runtime/trafficLimits/{$user['name']}", $user['trafficLimit'] );
    chmod( "/etc/seedbox/runtime/trafficLimits/{$user['name']}", 0600  );  // Restrict permissions to this file
    file_put_contents("/home/{$user['name']}/.trafficLimit", $user['trafficLimit']);
    chmod( "/home/{$user['name']}/.trafficLimit", 0664  );  // Restrict permissions to this file
    writeLog('Traffic limit set: ' . $user['trafficLimit']);
}

// Retracker config
/*$retrackerConfigPath = $userHomedirPath . "/www/rutorrent/share/users/{$user['name']}/settings";
if (mkdir($retrackerConfigPath, 0777, true)) {
    mkdir("/home/{$user['name']}/www/rutorrent/share/users/{$user['name']}/torrents", 0777, true);
    file_put_contents($retrackerConfigPath . '/retrackers.dat', 'O:11:"rRetrackers":4:{s:4:"hash";s:14:"retrackers.dat";s:4:"list";a:1:{i:0;a:1:{i:0;s:33:"http://149.5.241.17:6969/announce";}}s:14:"dontAddPrivate";s:1:"1";s:10:"addToBegin";s:1:"1";}');
    passthru("chown {$user['name']}.{$user['name']} {$retrackerConfigPath}");
    passthru("chown {$user['name']}.{$user['name']} {$retrackerConfigPath}/retrackers.dat");
    passthru("chown {$user['name']}.{$user['name']} /home/{$user['name']}/www/rutorrent/share/users/{$user['name']}");
    passthru("chown {$user['name']}.{$user['name']} /home/{$user['name']}/www/rutorrent/share/users/{$user['name']}/torrents");
}*/


// Crontab for the user
echo "Adding crontab\n";
passthru("crontab -u{$user['name']} /etc/seedbox/config/user.crontab.default");

// Setting file permissions
passthru("nohup /scripts/util/userPermissions.php {$user['name']} >> /dev/null 2>&1 &");

function writeLog($message) {
GLOBAL $user;
    $message = date('Y-m-d H:i:s') . " ({$user['name']}):\n" . $message . "\n";
    file_put_contents('/var/log/pmss/addUser.log', $message, FILE_APPEND);
}
