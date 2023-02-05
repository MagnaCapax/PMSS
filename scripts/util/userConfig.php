#!/usr/bin/php
<?php
# PMSS: User configuration
# Copyright (C) Magna Capax Finland Oy 2010-2023

#TODO Refactor, commenting etc. etc.
#TODO Save every detail so we can locally check the settings later on, AKA local user database
#TODO Better input parser, just like for userTrafficLimit.php
#TODO Rtorrent + deluge config should be completely transferred to within userspace
#TODO Deluge should not have special config in nginx, and should be changed like qBittorrent works
#TODO Make IOWeights actually work and include other cgroup parameters as well
#TODO Set port range for user based on user id, something like this: ((UserID-1000)*100)+1000 to give 100 ports for user \
#       to employ systematically the same always to remove slightest chance of conflicts
#TODO Check if docker rootless can be preinstalled just in skel
#TODO Update skel user .bashrc to have the docker settings
#TODO Should target about 30 lines of actual code when refactored properly, aka this should just be a "controller", see setting trafficlimit

require_once '/scripts/lib/rtorrentConfig.php';
require_once '/scripts/lib/update.php';

$usage = 'Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000]';
if (empty($argv[1]) or
    empty($argv[2]) or
    empty($argv[3]) ) die('need user name. ' . $usage . "\n");
    
$user = array(
    'name'      => $argv[1],
    'memory'    => (int) $argv[2],
    'quota'     => (int) $argv[3]
);
$user['id'] = (int) `id -u {$user['name']}`;
if (isset($argv[4])) $user['trafficLimit'] = (int) $argv[4];
if (isset($argv[5])) $user['CPUWeight'] = (int) $argv[5];
if (isset($argv[6])) $user['IOWeight'] = (int) $argv[6];

if (!isset($user['id']) OR $user['id'] < 1000) die("No system ID or user does not exist\n");
if (!file_exists("/home/{$user['name']}")) die("User does not exist\n");

$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false) die("No such user in passwd list\n");

if (!empty($user['trafficLimit']) && $user['trafficLimit'] > 1) passthru("/scripts/util/userTrafficLimit.php {$user['name']} {$user['trafficLimit']}");

// Check for valid weights and set default
if (empty($user['CPUWeight']) or (int) $user['CPUWeight'] == 0) $user['CPUWeight'] = 500;
if (empty($user['IOWeight']) or (int) $user['IOWeight'] == 0) $user['IOWeight'] = 500;


echo "Creating rTorrent config\n";
#rTorrent resource config - if different ratios are required. Refer to rTorrent config class for instructions on how to use
if (file_exists('/etc/seedbox/config/system.rtorrent.resources')) $rtorrentResources = unserialize( file_get_contents('/etc/seedbox/config/system.rtorrent.resources'));
    else $rtorrentResources = array();    // empty means it should default
	
// Get .rtorrent.rc template file
if (file_exists('/etc/seedbox/config/template.rtorrentrc')) $rtorrentTemplate = file_get_contents('/etc/seedbox/config/template.rtorrentrc');
    else $rtorrentTemplate = null;
    

#TODO here we could fetch config stuff from users home dir ... just saying!
$rtorrentConfig = new rtorrentConfig($rtorrentResources, $rtorrentTemplate);
$configuration = $rtorrentConfig->createConfig(
    array(
    	'ram' => $user['memory'],
    	'dht' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht'),
    	'pex' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex')
    )
);
$rtorrentConfig->writeConfig( $user['name'], $configuration['configFile']);


// ruTorrent configuration - mainly for linking to rTorrent
echo "Changing ruTorrent config\n";
updateRutorrentConfig($user['name'], $configuration['config']['scgiPort']);

// Rclone
if (!file_exists("/home/{$user['name']}/.rclonePort")) file_put_contents( "/home/{$user['name']}/.rclonePort", (int) round(rand(1500,65500)) );


// Deluge config!
if (!file_exists("/home/{$user['name']}/.config/deluge")) shell_exec("mkdir -p /home/{$user['name']}/.config/deluge;");
if (!file_exists("/home/{$user['name']}/dataUnfinished")) shell_exec("mkdir -p /home/{$user['name']}/.delugeUnfinished; chown {$user['name']}.{$user['name']} -R /home/{$user['name']}/dataUnfinished");
if (!file_exists("/home/{$user['name']}/.sessionDeluge")) shell_exec("mkdir -p /home/{$user['name']}/.delugeSession; chown {$user['name']}.{$user['name']} -R /home/{$user['name']}/.sessionDeluge");

// We do not want to constantly change deluge config due to the password
//if (!file_exists("/home/{$user['name']}/.config/deluge/core.conf")) {
#TODO We need to start copying the password or something...

  if (file_exists("/home/{$user['name']}/.delugePort"))
      $delugePort = (int) file_get_contents("/home/{$user['name']}/.delugePort");

  if (empty($delugePort) or
     $delugePort <1024 or
     $delugePort > 65000 or
     !is_int($delugePort)) $delugePort = $configuration['config']['scgiPort'];

  $delugeCoreConfig = str_replace(
      array('##USERNAME##', '##CACHE', '##DAEMONPORT'),
      array($user['name'],(int) ($user['memory'] * 1024 / 16),$delugePort),
    file_get_contents('/etc/seedbox/config/template.deluge.core.conf') );
  file_put_contents("/home/{$user['name']}/.config/deluge/core.conf", $delugeCoreConfig);

  $delugeHostlist = str_replace(
      '##DAEMONPORT',
      $delugePort,
  file_get_contents('/etc/seedbox/config/template.deluge.hostlist.conf') );
  file_put_contents("/home/{$user['name']}/.config/deluge/hostlist.conf", $delugeHostlist);
  if (!file_exists("/home/{$user['name']}/.config/deluge/hostlist.conf.1.2")) `ln -s /home/{$user['name']}/.config/deluge/hostlist.conf /home/{$user['name']}/.config/deluge/hostlist.conf.1.2`;

  $delugeWebConfig = str_replace(
      array('##WEBPORT', '##USER'),
      array($delugePort + 1, $user['name']),
  file_get_contents('/etc/seedbox/config/template.deluge.web.conf') );
  file_put_contents("/home/{$user['name']}/.config/deluge/web.conf", $delugeWebConfig);

  file_put_contents("/home/{$user['name']}/.delugePort", $delugePort);

  if (!file_exists("/home/{$user['name']}/.config/deluge/auth")) shell_exec("cp /etc/seedbox/config/template.deluge.auth /home/{$user['name']}/.config/deluge/auth");
  if (!file_exists("/home/{$user['name']}/.config/deluge/web.conf")) shell_exec("cp /etc/seedbox/config/template.deluge.web.conf /home/{$user['name']}/.config/deluge/web.conf");
  shell_exec("chown {$user['name']}.{$user['name']} -R /home/{$user['name']}/.config/");

//}

// qBittorrent Config!
#TODO transfer this as user space configurator too, more dynamic and try to preserve user made configs
#TODO Set to a static port, say userPortRange+5 or smthing (ie. user id = 1005, port range = 1500-1600, so qbit 1505)
if (!file_exists("/home/{$user['name']}/.config/qBittorrent/qBittorrent.conf")) {
    $qbittorrentTemplate = file_get_contents('/etc/seedbox/config/template.qbittorrent.conf');
    $qbittorrentPort = round( rand(1500, 65500) );

    if (!file_exists("/home/{$user['name']}/.config/qBittorrent") )
        mkdir("/home/{$user['name']}/.config/qBittorrent", 0770, true);

    $qbittorrentConfig = str_replace( array('##username', '##port'), array($user['name'], $qbittorrentPort), $qbittorrentTemplate );
    file_put_contents("/home/{$user['name']}/.config/qBittorrent/qBittorrent.conf", $qbittorrentConfig);
    file_put_contents("/home/{$user['name']}/.qbittorrentPort", $qbittorrentPort);
}


#TODO setting quota ought to be /scripts/util/userQuota.php or so. many small utils philosophy
// Set Quota
$filesLimitPerGb = 500;
$quota = $user['quota'] * 1024 * 1024;
$filesLimit = $user['quota'] * $filesLimitPerGb;
if ($filesLimit < 15000) $filesLimit = 15000;

$quotaBurst = floor($quota * 1.25);
$filesBurst = floor($filesLimit * 1.25);

passthru("setquota {$user['name']} {$quota} {$quotaBurst} {$filesLimit} {$filesBurst} -a");

// Let's restart rTorrent, this could also be achieved by touching restart file, but let's make it immediate!
$userPidFile = "/home/{$user['name']}/session/rtorrent.lock";
if (file_exists($userPidFile)) {    // Let's restart rTorrent
    $userPid = explode(':+', file_get_contents($userPidFile));
    $userPid = (int) $userPid;
    
    if ($userPid > 0)
        shell_exec("kill -9 {$userPid}");
}

if (file_exists('/bin/bash'))	// Set shell - to ensure right shell
	passthru("chsh -s /bin/bash {$user['name']}");


//Let's setup systemctl / cgroup limits
// Options: https://www.freedesktop.org/software/systemd/man/systemd.resource-control.html
$slicePath = "/etc/systemd/system/user-{$user['id']}.slice.d";
if (!file_exists($slicePath)) mkdir($slicePath);    // Hmm, questionable ...
$systemctlUserSliceTemplate = file_get_contents('/etc/seedbox/config/template.user-slice.conf');

$systemctlUserSlice = str_replace(
        array('##USER_MEMORY##', '##USER_MEMORY_MAX##', '##USER_CPUWEIGHT##', '##USER_IOWEIGHT##'),
        array($user['memory'], $user['memory'] * 2, $user['CPUWeight'], $user['IOWeight']),
$systemctlUserSliceTemplate);

file_put_contents($slicePath . '/99-pmss.conf', $systemctlUserSlice);
chmod($slicePath . '/99-pmss.conf', 0644);
//echo `systemctl restart user-{$user['id']}.slice`;
echo passthru("systemctl daemon-reload");


// Enable linger
echo passthru("loginctl enable-linger {$user['name']}");

// Install docker rootless
echo passthru("su {$user['name']} -c 'curl -fsSL https://get.docker.com/rootless | sh'");
echo passthru("su {$user['name']} -c 'wget https://github.com/docker/compose/releases/download/v2.14.2/docker-compose-linux-x86_64 -O ~/bin/docker-compose; chmod +x ~/bin/docker-compose; systemctl --user enable docker'");