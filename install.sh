#!/bin/bash
#Pulsed Media Seedbox Management Software package AKA Pulsed Media Software Stack "PMSS"
#
# LICENSE
# This work is licensed under the Creative Commons Attribution-ShareAlike 3.0 Unported License. 
# To view a copy of this license, visit http://creativecommons.org/licenses/by-sa/3.0/ or send a letter to Creative Commons, 171 Second Street, Suite 300, San Francisco, California, 94105, USA.

# Copyright 2010-2023 Magna Capax Finland Oy
#
# USE AT YOUR OWN RISK! ABSOLUTELY NO GUARANTEES GIVEN!
# Pulsed Media does not take any responsibility over usage of this package, and will not be responsible for any damages, injuries, losses, or anything else for that matter due to the
# usage of this package.
#
# Please note: Install this on a fresh, minimal install Debian 64bit.
#
# For help, see http://wiki.pulsedmedia.com
# Github: https://github.com/MagnaCapax/PMSS


# First Let's verify hostname
apt-get install vim quota -y
vi /etc/hostname

# Setup fstab for quota and /home array
echo "#### Setting up quota"
echo "You need to add ' usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1 ' to the device for which you want quota"
echo "
proc            /proc           proc    defaults,hidepid=2        0       0

# You need to add to the wanted device(s):
#usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1
" >> /etc/fstab
vi /etc/fstab


mount -o remount /home

## #TODO: Update to dselections, automate
# TODO this whole follow up section is yucky
# Package selections, and remove some defaults etc.
apt-get update; apt-get upgrade -y


apt-get remove samba-common exim4-base exim4 netcat netcat-traditonal netcat6 -y


apt-get install libncurses5-dev less -y
apt-get install libxmlrpc-c++4-dev libxmlrpc-c++4 -y    #Not found on deb8
apt-get install libxmlrpc-c++8v5 -y
apt-get install automake autogen build-essential libwww-curl-perl libcurl4-openssl-dev libsigc++-2.0-dev libwww-perl sysfsutils libcppunit-dev -y
apt-get install gcc g++ gettext glib-networking libglib2.0-dev libfuse-dev apt-transport-https -y

# Looks like debian now wants to install apache2 with php5-geoip
apt-get install php5 php5-cli php5-geoip php5-gd php5-mcrypt php-apc php5-cgi php5-sqlite php5-common php5-xmlrpc php5-curl -y  #Does not work on Deb10, as it has php7 autofails ;)
apt-get install php php-cli php-geoip php-gd php-apcu php-cgi php-sqlite3 php-common php-xmlrpc php-curl -y


apt-get install psmisc rsync subversion mktorrent -y
#apt-get -t testing install mktorrent rsync -y

apt-get install libncurses5 libncurses5-dev  links elinks lynx sudo -y
apt-get install libgettext-ruby-util -y #no more present in deb8
apt-get install pkg-config make openssl -y

#From update-step2 l:72, remove them from there circa 03/2016
apt-get install znc znc-perl znc-python znc-tcl -y
apt-get install gcc g++ gettext python-cheetah curl fuse glib-networking libglib2.0-dev libfuse-dev apt-transport-https -y  #Everythin installed already on deb8
apt-get install links elinks lynx ethtool p7zip-full smartmontools -y   #all but smart + p7zip already installed...
apt-get install flac lame lame-doc mp3diags lftp -y
#Following are for autodl-irssi
apt-get -y install libarchive-zip-perl libnet-ssleay-perl libhtml-parser-perl libxml-libxml-perl libjson-perl libjson-xs-perl libxml-libxslt-perl   #ssleay, parser-perl, xml-perl already installed on deb8
#apt-get install libcrypto++-dev libcrypto++-utils libcrypto++9 -y   #Needed on deb8 for libtorrent compile

apt-get install libssl-dev libssl1.1 mediainfo libmediainfo0v5 -y  ##TODO Yuck distro version dependant

apt-get install git

echo "### Setting up software"
mkdir ~/compile
cd /tmp
rm -rf PMSS*
if [ "${1:0:3}" = "git" ]; then
    git clone https://github.com/MagnaCapax/PMSS;
    cd PMSS;
    git checkout "${1:4}";
else
    wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tarball_url/ {print $(NF-1)}' | xargs wget -O PMSS.tar.gz;
    mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1;
    cd PMSS;
fi

bash soft.sh

#TODO Transfer over the proper rc.local + sysctl configs
echo "### Setting up HDD schedulers"
echo "
#Pulsed Media Config
block/sda/queue/scheduler = bfq
block/sdb/queue/scheduler = bfq
block/sdc/queue/scheduler = bfq
block/sdd/queue/scheduler = bfq
block/sde/queue/scheduler = bfq
block/sdf/queue/scheduler = bfq

block/sda/queue/read_ahead_kb = 1024
block/sdb/queue/read_ahead_kb = 1024
block/sdc/queue/read_ahead_kb = 1024
block/sdd/queue/read_ahead_kb = 1024
block/sde/queue/read_ahead_kb = 1024
block/sdf/queue/read_ahead_kb = 1024

net.ipv4.ip_forward = 1
" >> /etc/sysctl.d/1-pmss-defaults.conf

#TODO Transfer over the proper root .bashrc
echo "### Setting up basic shell env"
echo "alias ls='ls --color=auto'" >> /root/.bashrc
echo "PATH=\$PATH:/scripts" >> /root/.bashrc


echo "### setting /home permissions"
chmod o-rw /home

echo "### Daemon configurations, quota checkup"
/scripts/update.php
/scripts/util/setupRootCron.php
/scripts/util/setupSkelPermissions.php
/scripts/util/quotaFix.php
/scripts/util/ftpConfig.php

#TODO Ancient API, but it may yet prove to be useful so not quite yet removed -AU
#/scripts/util/setupApiKey.php

