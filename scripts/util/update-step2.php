#!/usr/bin/php
<?php
require_once '/scripts/lib/update.php';         // Load update related lib
require_once '/scripts/lib/rtorrentConfig.php'; // Load rTorrentConfig class
$rtorrentConfig = new rtorrentConfig;
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$debianVersion = file_get_contents('/etc/debian_version');
$serverHostname = trim( file_get_contents('/etc/hostname') );

// If var run does not exist, create it. Deb8 likes to remove this if empty?
if (!file_exists('/var/run/pmss'))
	mkdir('/var/run/pmss', 0770);

// Mark update date
file_put_contents(date('Y-m-d'), '/var/run/pmss/updated');

$wheezyRepos = <<<EOF
deb http://debian.bhs.mirrors.ovh.net/debian/ wheezy main non-free
deb-src http://debian.bhs.mirrors.ovh.net/debian/ wheezy main non-free

deb http://security.debian.org/ wheezy/updates main non-free
deb-src http://security.debian.org/ wheezy/updates main non-free

deb http://ppa.launchpad.net/jcfp/ppa/ubuntu precise main

deb http://www.bunkus.org/debian/wheezy/ ./

EOF;

/* 2019/04: updates repo was removed, backports archived, bunkus has not worked for a loooong time.
$jessieRepos = <<<EOF
deb http://ftp.nl.debian.org/debian/ jessie main non-free
deb-src http://ftp.nl.debian.org/debian/ jessie main non-free

deb http://security.debian.org/ jessie/updates main non-free
deb-src http://security.debian.org/ jessie/updates main non-free

# jessie-updates, previously known as 'volatile'
deb http://ftp.nl.debian.org/debian/ jessie-updates main non-free
deb-src http://ftp.nl.debian.org/debian/ jessie-updates main non-free

deb http://www.bunkus.org/debian/jessie/ ./

#Sabnzbd repo for jessie is same as wheezy (Ubuntu precise)
deb http://ppa.launchpad.net/jcfp/ppa/ubuntu precise main

#Backports - ffmpeg etc.
deb http://ftp.debian.org/debian jessie-backports main

EOF;
*/

$jessieRepos = <<<EOF
deb http://ftp.funet.fi/debian/ jessie main non-free
deb-src http://ftp.funet.fi/debian/ jessie main non-free

deb http://security.debian.org/ jessie/updates main non-free
deb-src http://security.debian.org/ jessie/updates main non-free

#Sabnzbd repo for jessie is same as wheezy (Ubuntu precise)
deb http://ppa.launchpad.net/jcfp/ppa/ubuntu precise main

#Backports - ffmpeg etc.
deb http://archive.debian.org/debian/ jessie-backports main

EOF;

$busterRepos = <<<EOF
deb http://www.nic.funet.fi/debian/ buster main non-free contrib
deb-src http://www.nic.funet.fi/debian/ buster main non-free contrib

deb http://security.debian.org/debian-security buster/updates main non-free contrib
deb-src http://security.debian.org/debian-security buster/updates main non-free contrib

# buster-updates, previously known as 'volatile'
deb http://www.nic.funet.fi/debian/ buster-updates main non-free contrib
deb-src http://www.nic.funet.fi/debian/ buster-updates main non-free contrib

deb http://www.nic.funet.fi/debian/ buster-backports main non-free contrib
EOF;



/****  END CONFIG ****/

$currentRepos = sha1(file_get_contents('/etc/apt/sources.list'));

switch($debianVersion[0]) {
    case 7:
        if ($currentRepos != sha1($wheezyRepos) ) {
            file_put_contents('/etc/apt/sources.list', $wheezyRepos);
//            passthru('apt-get update; apt-get upgrade -y;');
        }
        break;
        
    case 8:
        if ($currentRepos != sha1($jessieRepos) ) {
            file_put_contents('/etc/apt/sources.list', $jessieRepos);
            passthru('echo \'Acquire::Check-Valid-Until "false";\' >/etc/apt/apt.conf.d/90ignore-release-date');
            passthru('apt-get clean;');
        }
        break;

    case 1:	// Debian10
        echo `apt update -y`;	// Get new minor version update
        if ($currentRepos != sha1($busterRepos) ) {
            file_put_contents('/etc/apt/sources.list', $busterRepos);
        }
        break;


}

// Localnet file location fix
if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
    `mv /etc/seedbox/localnet /etc/seedbox/config/localnet`;
}

//Install latest rc.local file and execute it
`wget http://pulsedmedia.com/remote/rc-local.txt -O /etc/rc.local; chmod u+x /etc/rc.local; /etc/rc.local`;

include_once '/scripts/lib/apps/packages.php';


// Was ist das? something relating to Perl but why and what ...
//passthru('(echo y; echo y; echo y;)|cpan Archive::Zip Net::SSLeay HTML::Entities XML::LibXML Digest::SHA JSON JSON::XS');   // Perl modules


if ($debianVersion[0] != 1) passthru("/etc/init.d/lighttpd stop; update-rc.d lighttpd stop 2 3 4 5; update-rc.d lighttpd remove; killall -9 lighttpd; killall -9 php-cgi; update-rc.d nginx defaults");
	else passthru("/etc/init.d/lighttpd stop; systemctl disable lighttpd; killall -9 lighttpd; killall -9 php-cgi; systemctl enable nginx");

passthru("/scripts/util/configureLighttpd.php");
passthru("/scripts/util/createNginxConfig.php");
passthru("/scripts/util/checkUserHtpasswd.php");
passthru("/etc/init.d/nginx restart");
passthru("/scripts/cron/checkLighttpdInstances.php");
passthru('chmod 751 /home; chmod 740 /home/*');

// Set locales
`sed -i 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/g' /etc/locale.gen; locale-gen`;
`sed -i 's/LANG=en_US\n/LANG=en_US.UTF-8/g' /etc/default/locale`;




#TODO glob and foreach include?
include_once '/scripts/lib/apps/vnstat.php';	// Vnstat installer + configuration
include_once '/scripts/lib/apps/pyload.php';	// pyload installer
include_once '/scripts/lib/apps/megatools.php';	// Megatools installer
include_once '/scripts/lib/apps/mono.php';		// Mono installer
include_once '/scripts/lib/apps/sonarr.php';	// Sonarr installer
include_once '/scripts/lib/apps/radarr.php';
include_once '/scripts/lib/apps/btsync.php';	// Btsync & Resilio sync installer
include_once '/scripts/lib/apps/syncthing.php'; // Syncthing installer
include_once '/scripts/lib/apps/openvpn.php';   // OpenVPN installer & configurator
include_once '/scripts/lib/apps/rclone.php';	// Rclone updater & installer
include_once '/scripts/lib/apps/python.php';	// Python/PIP etc. related stuff
include_once '/scripts/lib/apps/rtorrent.php';
include_once '/scripts/lib/apps/iprange.php';
include_once '/scripts/lib/apps/firehol.php';
include_once '/scripts/lib/apps/filebot.php';
include_once '/scripts/lib/apps/deluge.php';


passthru('/scripts/util/setupLetsEncrypt.php noreplies@pulsedmedia.com');


// Autodl irssi cfg
/*if (!file_exists('/etc/autodl.cfg')) {
    $autodlConfig = <<<EOF
[options]
allowed = watchdir
EOF;
    file_put_contents('/etc/autodl.cfg', $autodlConfig);
}*/
if (file_exists('/etc/autodl.cfg')) {
    unlink('/etc/autodl.cfg');
}

#Don't run certain programs as "server wide" daemons, as we always need per user for these
$servicesToCheck = array(
    'btsync',
    'rslsync',
    'pyload',
    'sabnzbdplus',
    'lighttpd'
);
foreach ($servicesToCheck AS $thisService) {
    if (file_exists('/etc/init.d/' . $thisService)) passthru("/etc/init.d/{$thisService} stop");
    if ($debianVersion[0] != 1) passthru("update-rc.d {$thisService} disable");
		else passthru("systemctl disable {$thisService}");
}

$fstab = file_get_contents('/etc/fstab');
if (strpos($fstab, 'cgroup') === false) {   // Cgroups not installed
    passthru('apt-get install cgroup-bin -y');
    $mount = "\ncgroup  /sys/fs/cgroup  cgroup  defaults  0   0\n";
    file_put_contents('/etc/fstab', $mount, FILE_APPEND);
    `mount /sys/fs/cgroup`;
}

// Setup lighttpd HTTPS access
/* SHOULD NOT BE REQUIRED ANYMORE AS WE DO NOT RUN GLOBAL LIGHTTPD INSTANCE   --- remove 09/2020 completely if no issues
if (!file_exists('/etc/lighttpd/server.pem')) {
    passthru('rm -rf /etc/lighttpd/ssl/customseedbox.com');
    if (file_exists('/tmp/createCert-pulsed')) unlink('/tmp/createCert-pulsed');    
    file_put_contents('/tmp/createCert-pulsed', <<<EOF
RANDFILE                = /dev/urandom

[ req ]
default_bits            = 1024
default_keyfile         = privkey.pem
distinguished_name      = req_distinguished_name
prompt                  = no
policy                  = policy_anything

[ req_distinguished_name ]
commonName                      = {$serverHostname}
EOF
   );
   passthru("openssl req -config /tmp/createCert-pulsed -new -x509 -days 3650 -nodes -out '/etc/lighttpd/server.pem' -keyout '/etc/lighttpd/server.pem' > /dev/null 2>&1");
   passthru('chmod 600 /etc/lighttpd/server.pem');
   passthru('lighty-enable-mod ssl;');
}
*/


// Install mediainfo
if (!file_exists('/usr/bin/mediainfo')) {
    $current = getcwd();
    mkdir('/tmp/mediainfo');
    chdir('/tmp/mediainfo');
    
	// $mediaVersion is only mentioned here ...
    if (!empty($mediaVersion)) {
        passthru("wget http://pulsedmedia.com/remote/pkg/libzen0_0.4.24-1_amd64.Debian_{$mediaVersion}.deb");
        passthru("wget http://pulsedmedia.com/remote/pkg/libmediainfo0_0.7.53-1_amd64.Debian_{$mediaVersion}.deb");
        passthru("wget http://pulsedmedia.com/remote/pkg/mediainfo_0.7.52-1_amd64.Debian_{$mediaVersion}.deb");
        passthru("dpkg -i libzen0_0.4.24-1_amd64.Debian_{$mediaVersion}.deb");
        passthru("dpkg -i libmediainfo0_0.7.53-1_amd64.Debian_{$mediaVersion}.deb");
        passthru("dpkg -i mediainfo_0.7.52-1_amd64.Debian_{$mediaVersion}.deb");
    
    }
    chdir($current);
}




// Lighttpd config security update
`chmod 750 /etc/lighttpd/lighttpd.conf`;
`chown www-data.www-data /etc/lighttpd/lighttpd.conf`;
`chown www-data.www-data /etc/lighttpd/.htpasswd`;
`chmod 750 /etc/lighttpd/.htpasswd`;


// Per user updates
$changedConfig = array();
$rutorrentIndexSha = sha1( file_get_contents('/etc/skel/www/rutorrent/index.html') );

foreach($users AS $thisUser) {
    if (empty($thisUser)) continue; // just to be on safe side.
    if (!file_exists("/home/{$thisUser}/.rtorrent.rc")) continue;   // Probably a removed user
    if (!file_exists("/home/{$thisUser}/data")) continue; // probably removed user
    if (file_exists("/home/{$thisUser}/www-disabled")) continue; // User is suspended
 
    echo "***** Updating user {$thisUser}\n";

/* Commented out 20/07/2020 -- this looks like something from quite a few years back
	// Update user's rtorrent config    
	$userRtorrentConfig = file_get_contents("/home/{$thisUser}/.rtorrent.rc");
    $userRtorrentConfig = str_replace(
        array(
            "umask = 0002\n",
            "hash_interval = 300\n",
            "hash_max_tries = 2\n",
			"use_udp_trackers = yes\n",
        ), '', $userRtorrentConfig);
    file_put_contents("/home/{$thisUser}/.rtorrent.rc", $userRtorrentConfig);
*/  

    // temp directory for ruTorrent
    if (!file_exists("/home/{$thisUser}/.tmp")) {
        mkdir("/home/{$thisUser}/.tmp");
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/.tmp");
    }

    # Add irssi basic config
	# TODO this probably can be removed soon -Aleksi 20/07/2020
    if (!file_exists("/home/{$thisUser}/.irssi")) {
        passthru("mkdir /home/{$thisUser}/.irssi; cp /etc/skel/.irssi/config /home/{$thisUser}/.irssi/; chown {$thisUser}.{$thisUser} /home/{$thisUser}/.irssi -R");
    }
    

    
    
    
    // Recycle dir + perms?
    $thisFile = "/home/{$thisUser}/www/recycle";
    if (!file_exists($thisFile)) {
        mkdir($thisFile);
        passthru("chown {$thisUser}.{$thisUser} {$thisFile}");
        passthru("chmod 771 {$thisFile}");
    }
    
    // Update specific files
    //updateUserFile("www/rutorrent/index.html", $thisUser);      // Update rutorrent index.html	-- which prevented rutorrent update code from running
    /*updateUserFile('www/rutorrent/plugins/create/conf.php', $thisUser);
    updateUserFile('www/rutorrent/plugins/hddquota/action.php', $thisUser);*/
    updateUserFile('.rtorrentExecute.php', $thisUser);
    updateUserFile('.rtorrentRestart.php', $thisUser);
    updateUserFile('.bashrc', $thisUser);
    updateUserFile('.scriptsInc.php', $thisUser);
    updateUserFile('.lighttpd/php.ini', $thisUser);
    updateUserFile('www/filemanager.php', $thisUser);
    if (!file_exists("/home/{$thisUser}/www/owncloud")) updateUserFile('www/setup-owncloud.php', $thisUser);
    updateUserFile("www/ajax/content.php", $thisUser);
    updateUserFile("www/openvpn-config.tgz", $thisUser);  // OpenVPN Config
	// ruTorrent fix for 0.9.8 / 0.13.8 rtorrent/libtorrent versions
    updateUserFile("www/rutorrent/js/content.js", $thisUser);
    updateUserFile("www/rutorrent/php/settings.php", $thisUser);
   
    //Very old compatibility thingy for phpXplorer to Ajaxplorer migration ... needed like around 2012, thanks Mattx for reminding -Aleksi 23/11/2022
    if (file_exists("/home/{$thisUser}/www/phpXplorer")) unlink("/home/{$thisUser}/www/phpXplorer");
 
    // If doing recursive glob (slightly problematic) we could patch update whole GUI on each server update... ;)
    $files = glob("/etc/skel/www/rutorrent/plugins/hddquota/*");    #TODO Figure out this path fiasko right here!
    foreach($files AS $thisFile) {
        $thisFile = str_replace('/etc/skel/', '', $thisFile);
        updateUserFile($thisFile, $thisUser);
    }

    // Themes update to include https://github.com/artyuum/3rd-party-ruTorrent-Themes and make MaterialDesign the default
    updateUserFile("www/rutorrent/plugins/theme/conf.php", $thisUser);
    $themesPath = "/home/{$thisUser}/www/rutorrent/plugins/theme/themes/";
    $themesToCheck = array(
        'Agent34',
        'Agent46',
        'OblivionBlue',
        'FlatUI_Dark',
        'FlatUI_Light',
        'FlatUI_Material',
        'MaterialDesign',
        'club-QuickBox'
    );
    foreach($themesToCheck AS $thisTheme) {
        if (!file_exists($themesPath . $thisTheme))
            `cp -r /etc/skel/www/rutorrent/plugins/theme/themes/{$thisTheme} {$themesPath}; chown {$thisUser}.{$thisUser} {$themesPath}/{$thisTheme} -R;`;

    }



    ## UPDATE RuTorrent
    if (!file_exists("/home/{$thisUser}/www/oldRutorrent-3") &&
        //strpos( file_get_contents("/home/{$thisUser}/www/rutorrent/plugins/_noty/plugin.info"), 'plugin.version: 3.7' ) === false  ) {
        $rutorrentIndexSha != sha1( file_get_contents("/home/{$thisUser}/www/rutorrent/index.html") ) ) {

        
        echo "****** Updating ruTorrent\n";
        echo "******* Backing up old as 'oldRutorrent-3'\n";
        passthru("mv /home/{$thisUser}/www/rutorrent /home/{$thisUser}/www/oldRutorrent-3");
        echo "******* Copying new rutorrent from skel\n";
        passthru("cp -Rp /etc/skel/www/rutorrent /home/{$thisUser}/www/");
        echo "******* Configuring\n";
        passthru("cp -p /home/{$thisUser}/www/oldRutorrent-3/conf/config.php /home/{$thisUser}/www/rutorrent/conf/");       // Base config
        passthru("cp -rp /home/{$thisUser}/www/oldRutorrent-3/share/* /home/{$thisUser}/www/rutorrent/share/");   // User settings
        
        // New additions to ruTorrent config!
/*        $thisUserRtorrentConfig = $rtorrentConfig->readUserConfig( $thisUser );
        $thisUserScgiPort = explode(':', $thisUserRtorrentConfig['scgi_port']);
        if (isset($thisUserScgiPort[1])) $thisUserScgiPort = $thisUserScgiPort[1];
*/

        updateRutorrentConfig($thisUser, 1);
        
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent");
        passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent -R");
        passthru("chmod 751 /home/{$thisUser}/www/rutorrent");
        passthru("chmod 751 /home/{$thisUser}/www/rutorrent -R");
    }
    
    // Remove plugins we do not want to be included. CPULoad is misleading and autotools doesn't work anymore, maintainer has disappeared
    if (file_exists("/home/{$thisUser}/www/rutorrent/plugins/cpuload")) shell_exec("rm -rf /home/{$thisUser}/www/rutorrent/plugins/cpuload");
//    if (file_exists("/home/{$thisUser}/www/rutorrent/plugins/check_port")) shell_exec("rm -rf /home/{$thisUser}/www/rutorrent/plugins/check_port");

    if (!file_exists("/home/{$thisUser}/www/rutorrent/plugins/unpack")) {
        shell_exec("cp -Rp /etc/skel/www/rutorrent/plugins/unpack /home/{$thisUser}/www/rutorrent/plugins/unpack");
        shell_exec("chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/plugins/unpack -R; chmod 755 /home/{$thisUser}/www/rutorrent/plugins/unpack -R");
    }
    //if (file_exists("/home/{$thisUser}/www/rutorrent/plugins/check_port")) shell_exec("rm -rf /home/{$thisUser}/www/rutorrent/plugins/check_port");     // Doesn't function anymore
    
    // Retracker config
    $retrackerConfigPath = "/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings";
    /*if (!file_exists($retrackerConfigPath . '/retrackers.dat')) {
        if (!file_exists($retrackerConfigPath)) {
            mkdir($retrackerConfigPath, 0777, true);
            
            passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/share/users/{$thisUser}");
            passthru("chown {$thisUser}.{$thisUser} /home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/torrents");
        }
        
        if (file_exists($retrackerConfigPath))
            file_put_contents($retrackerConfigPath . '/retrackers.dat', 'O:11:"rRetrackers":4:{s:4:"hash";s:14:"retrackers.dat";s:4:"list";a:1:{i:0;a:1:{i:0;s:33:"http://149.5.241.17:6969/announce";}}s:14:"dontAddPrivate";s:1:"1";s:10:"addToBegin";s:1:"1";}');
    }*/

    if (file_exists($retrackerConfigPath . '/retrackers.dat')) {
        $retrackCurrent = trim( file_get_contents($retrackerConfigPath . '/retrackers.dat') );
        if (sha1($retrackCurrent) == '9958caa274c2df67ea6702772821856365bc1201') unlink($retrackerConfigPath . '/retrackers.dat');
    }
    
    if (!file_exists("/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/torrents") &&
        file_exists("/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}") ) {
        
        mkdir("/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/torrents", 0777, true);
        passthru("chown {$thisUser}.{$thisUser} {$retrackerConfigPath}");
    }
    
    $thisRssDirectory = "/home/{$thisUser}/www/rutorrent/share/settings/rss";
    if (!file_exists($thisRssDirectory)) {
        mkdir($thisRssDirectory);
        passthru("chown {$thisUser}.{$thisUser} {$thisRssDirectory}");
        echo "\t*** Created RSS Settings folder\n";
    }

    
        // Let's update permissions
    passthru("/scripts/util/userPermissions.php {$thisUser}");

   // Remove the logging things, logged way way way too much. But only remove if it's the original way too verbose logging
   if (file_exists("/home/{$thisUser}/.rtorrent.rc.custom")) {
     $rcCustomSha = sha1(file_get_contents("/home/{$thisUser}/.rtorrent.rc.custom"));
 
     if ($rcCustomSha == 'dcf21704d49910d1670b3fdd04b37e640b755889' or
         $rcCustomSha == 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a' )
             shell_exec("cp /etc/skel/.rtorrent.rc.custom /home/{$thisUser}/"); //unlink("/home/{$thisUser}/.rtorrent.rc.custom");
   }   
   
}

/*
// Let's setup quota vfsv1
$fstabFile = file_get_contents('/etc/fstab');
if (!empty($fstabFile)) {
	$newFstab = str_replace('vfsv0', 'vfsv1', $fstabFile);
        if ($fstabFile != $newFstab && !empty($newFstab)) {
		file_put_contents('/etc/fstab', $newFstab);
		passthru('quotaoff -a; mount -o remount /home; quotaon -a; /scripts/util/quotaFix.php');

	}
}
*/

// Allow keybased auth
// Need to change line:
// #AuthorizedKeysFile     %h/.ssh/authorized_keys
$sshdConfig = file_get_contents('/etc/ssh/sshd_config');
$sshdConfigChanged = str_replace('#AuthorizedKeysFile', 'AuthorizedKeysFile', $sshdConfig);
if ($sshdConfig != $sshdConfigChanged) {
    echo "# Allowing SSH Key based authentication.\n";
    copy('/etc/ssh/sshd_config', '/etc/ssh/pmss.sshd_config');  // Backup original
    file_put_contents('/etc/ssh/sshd_config', $sshdConfigChanged);
    passthru('/etc/init.d/ssh restart');
}


// Systemd default unit
if (!file_exists('/usr/lib/systemd/user-.slice.d/99-pmss.conf')) {
    echo `cp -p /etc/seedbox/config/template.user-slices-pmss.conf /usr/lib/systemd/system/user-.slice.d/99-pmss.conf; chmod 644 /usr/lib/systemd/system/user-.slice.d/99-pmss.conf; systemctl daemon-reload`;
}


/**** Setup srvmgmt if this is a .pulsedmedia.com server
currently not in use
if (strpos($serverHostname, '.pulsedmedia.com') !== false) {
    // Let's see if we need to create srvmgmt account
    $passwd = file_get_contents('/etc/passwd');
    if (strpos($passwd, 'srvmgmt:') === false) {    // Check if the srvmgmt account doesn't exist
        // Yes we do!
        echo "# Adding srvmgmt account\n";
        passthru('useradd --skel /etc/seedbox/skel/srvmgmt -m srvmgmt');
        passthru('chsh -s /bin/secureShell.php srvmgmt');
        
        `chattr +a /home/srvmgmt/.bashrc`;
        `chattr +a /home/srvmgmt/.bash_history`;
        
    }
    
    if (file_exists('/bin/secureShell.php')) {
        #TODO Validate against SHA1 from remote server
        `chattr +a /bin/secureShell.php`;
    }
    
}
****/



// Create testfile :)
if (!file_exists('/var/www/testfile') or
    filesize('/var/www/testfile') != 104857600 ) {
        
    `dd if=/dev/urandom of=/var/www/testfile bs=1M count=100`;
}


// Disallow atop for regular users
chmod('/usr/bin/atop', 0750);

// Setup root crons
`/scripts/util/setupRootCron.php`;

/* Not using this method currently - afaik no one is
if (!file_exists('/etc/seedbox/config/api.remoteKey')) {
    unlink('/etc/seedbox/config/api.localKey');
    passthru('/scripts/util/setupApiKey.php');
}
*/




passthru("/scripts/util/configureLighttpd.php");
passthru("/scripts/util/createNginxConfig.php");
passthru("/scripts/util/checkUserHtpasswd.php");
passthru("/etc/init.d/nginx restart");
passthru("/scripts/cron/checkLighttpdInstances.php");

passthru('/scripts/util/setupSkelPermissions.php');
passthru('/scripts/util/setupRootCron.php');
passthru('/scripts/util/ftpConfig.php');
//passthru('/scripts/util/setupApiKey.php');

passthru('/scripts/listUsers.php | xargs -n1 -I\'{1}\' crontab -u {1} /etc/seedbox/config/user.crontab.default');



$networkConfigFile = '/etc/seedbox/config/network';
if (!file_exists($networkConfigFile)) {

    $networkConfig = <<<EOF
<?php
#Default settings, change these to suit your system. Speeds are in mbits
return array(
    'interface' => 'eth0',
    'speed' => '1000',
    'throttle' => array(
      'min' => 50,
      'max' => 100,
      'soft' => 250,          // Near limit so soft limit
      'limitSoft' => 80,      // % where soft limit is enabled
      'limitExceedMax' => 20  // % before going to minimum limit

    )

);
EOF;

    file_put_contents($networkConfigFile, $networkConfig);

    passthru('vim /etc/seedbox/config/network');

}

// Setup network monitoring etc.
`/scripts/util/setupNetwork.php`;

// Following should be moved to their own file eventually
`chmod o-r /var/log/wtmp /var/run/utmp  /usr/bin/netstat /usr/bin/who /usr/bin/w`;

