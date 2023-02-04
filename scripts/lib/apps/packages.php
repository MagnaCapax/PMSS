<?php
// Manage apt packages

passthru('apt-get update; apt-get full-upgrade -y;');


// Following from install.sh

passthru('apt-get install lighttpd lighttpd-mod-webdav proftpd-basic -y; /etc/init.d/lighttpd stop;');
passthru('apt-get install screen mc wget gawk subversion libtool libncurses5 sqlite locate ntpdate -y');
passthru('apt-get remove openvpn -y');
passthru('apt-get install python-pycurl python-crypto python-cheetah -y');
if ($debianVersion[0] == 7) passthru('apt-get install python-central -y');   #Not found on deb8
passthru('apt-get install zip unzip bwm-ng sysstat apache2-utils irssi iotop ethtool -y');
if ($debianVersion[0] >= 8 or
    $debianVersion[0] == 1) passthru('apt-get install unrar-free unp -y');   # Deb8, Deb10
    else passthru('apt-get install unrar rar php-apc -y');

if ($debianVersion[0] == 1) passthru('apt-get install libzen0v5 sox tmux tree ncdu weechat php7.3-xml php7.3-zip php-mbstring -y; apt remove avahi-daemon mediainfo libmediainfo0v5 -y; apt install qbittorrent-nox -y; wget https://mediaarea.net/repo/deb/repo-mediaarea_1.0-20_all.deb && dpkg -i repo-mediaarea_1.0-20_all.deb && apt-get update; apt-get install mediainfo libmediainfo0v5 -y');
	else `apt-get install sox nzbget tmux tree ncdu weechat -y`;

passthru('apt-get install zsh atop -y');

// Following from update-step2 prior 29/04/2019
passthru('apt-get -f install -y');  # To fix potentially broken dependencies
passthru('apt-get remove netcat netcat-traditional mercurial -y');
passthru('apt-get remove netcat6 go -y');
passthru('apt-get install aria2 htop mtr mktorrent -y');
passthru('apt-get install genisoimage xorriso -y');
passthru('apt-get install uidmap -y');  // no fuse-overlayfs, supplied by docker
 
passthru('apt-get install links elinks lynx ethtool zip p7zip-full smartmontools flac lame lame-doc mp3diags gcc g++ gettext python-cheetah fuse glib-networking libglib2.0-dev libfuse-dev apt-transport-https pigz -y');
passthru('apt-get install -t buster-backports curl libcurl4 -y');	// Fixes rtorrent crashes circa 12/2022
passthru('apt-get install unionfs-fuse sshfs s3fs -y');
passthru('apt-get install ranger nethack-console -y');
// Spidermonkey
if ($debianVersion[0] == 1) passthru('apt-get install libmozjs-52-0 libmozjs-60-0 -y');
    else passthru('apt-get install libmozjs185-1.0 libmozjs-24-0 -y');

// Kernel backport for buster
if ($debianVersion[0] == 1 && $debianVersion[1] == 0) {
   echo `apt install -t buster-backports linux-image-amd64 firmware-bnx2 firmware-bnx2x -y;`;
}


// Following is for autodl-irssi required packages
passthru('apt-get -y install libarchive-zip-perl libnet-ssleay-perl libhtml-parser-perl libxml-libxml-perl libjson-perl libjson-xs-perl libxml-libxslt-perl');

// New additional packages, do not remove 03/2016
passthru('apt-get -y install lftp');

passthru("apt-get install nginx ntp -y; /etc/init.d/nginx stop");

// Let's fix python-pip
//passthru('apt-get install python-pip libffi-dev python-dev -y');
passthru('apt-get install libffi-dev python-dev python3-venv -y');
if ($debianVersion[0] != 1) passthru('apt-get remove python-pip -y');
	else passthru('apt-get install python-pip -y');

#Install Sabnzbdplus
if (!file_exists('/usr/bin/sabnzbdplus')) {
    echo "## Installing Sabnzbdplus\n";
    //passthru('echo "deb http://ppa.launchpad.net/jcfp/ppa/ubuntu precise main" | tee -a /etc/apt/sources.list; apt-key adv --keyserver hkp://pool.sks-keyservers.net:11371 --recv-keys 0x98703123E0F52B2BE16D586EF13930B14BB9F05F');
    if ($debianVersion[0] != 1) passthru('apt-key adv --keyserver hkp://pool.sks-keyservers.net:11371 --recv-keys 0x98703123E0F52B2BE16D586EF13930B14BB9F05F');
    passthru('apt-get install sabnzbdplus -y;');
}



if ($debianVersion[0] < 8 &&
    $debianVersion[0] > 1) {  // Deb7 or older
    passthru('apt-get install znc znc-extra -y');
    passthru('apt-get -y install libdigest-sha-perl');
    passthru('apt-get install libzen0 mediainfo libmediainfo0 -y');

}
if ($debianVersion[0] >= 8 or
    $debianVersion[0] == 1) {    // Deb8, 9 or 10
    passthru('apt-get install znc znc-perl znc-tcl znc-python git -y;');
    passthru('apt-get install git -y'); // For unknown reason git won't install on above line, but rest of the packages do

    #Let's install pythont3+acd cli
    passthru('apt-get install python3 python3-pip python-virtualenv -y;');
    passthru('pip3 install --upgrade git+https://github.com/yadayada/acd_cli.git;');

    if (!file_exists('/usr/bin/ffmpeg') ) {
        passthru('apt-get install ffmpeg -y');
    }

    passthru('systemctl disable lighttpd'); // Stop lighttpd starting

}


#Install mkvtoolnix
if (!file_exists('/usr/bin/mkvextract')) {
    echo "## Installing mkvtoolnix\n";
    if ($debianVersion[0] == 7) passthru('wget -q -O - https://www.bunkus.org/gpg-pub-moritzbunkus.txt | sudo apt-key add -; apt-get update; ');
    passthru('apt-get install mkvtoolnix -y');
}

// Veeeery old legacy probably no need for this
passthru('apt-get remove munin -y');
passthru('apt-get install sudo -y');
passthru('apt-get remove consolekit -y');	// remove consolekit
#Install Expect
passthru('apt-get install expect -y');

#Compile firehol
if (!file_exists('/sbin/ipset'))
    passthru('apt-get install ipset -y');   #IPSet is required for Firehol


