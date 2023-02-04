#!/bin/bash
#Pulsed Media Seedbox Management Software package AKA Pulsed Media Software Stack "PMSS"
#
#LICENSE
#This work is licensed under the Creative Commons Attribution-ShareAlike 3.0 Unported License. 
#To view a copy of this license, visit http://creativecommons.org/licenses/by-sa/3.0/ or send a letter to Creative Commons, 171 Second Street, Suite 300, San Francisco, California, 94105, USA.

#Copyright 2010-2014 Pulsed Media
#
# USE AT YOUR OWN RISK! ABSOLUTELY NO GUARANTEES GIVEN!
# Pulsed Media does not take any responsibility over usage of this package, and will not be responsible for any damages, injuries, losses, or anything else for that matter due to the
# usage of this package.
#
# Please note: Install this on a fresh Debian 7 64bit.
# Distro has to be 64bit. Ubuntu *UNTESTED* but might work
# RPM based distros will NOT work, without installing all packages and software by hand
#
# For help, see http://wiki.pulsedmedia.com


#ProFTP here because it asks questions
apt-get install vim -y
vi /etc/hostname

echo "#### Setting up quota"
echo "You need to add ' usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1 ' to the device for which you want quota"
echo "
proc            /proc           proc    defaults,hidepid=2        0       0

# You need to add to the wanted device(s):
#usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1
" >> /etc/fstab
vi /etc/fstab

apt-get install proftpd-basic quota -y

mount -o remount /home

## TODO: Update to dselections, automate
# Package selections, and remove some defaults etc.
apt-get update
apt-get upgrade -y




apt-get remove samba-common exim4-base exim4 netcat netcat-traditonal netcat6 -y




apt-get install libncurses5-dev less -y
apt-get install libxmlrpc-c++4-dev libxmlrpc-c++4 -y    #Not found on deb8
apt-get install libxmlrpc-c++8-dev libxmlrpc-c++8 cfv -y    #Not found on deb10
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
apt-get install libssl-dev libssl1.0.0 mediainfo libmediainfo0 -y  #not on deb10 anymore
apt-get install libssl-dev libssl1.1 mediainfo libmediainfo0v5 -y


#echo "#### Compiling xml-rpc"
#mkdir compile
#cd compile
#svn checkout http://svn.code.sf.net/p/xmlrpc-c/code/advanced xmlrpc-c -r 1831
#svn checkout http://svn.code.sf.net/p/xmlrpc-c/code/advanced xmlrpc-c -r 2776
#cd xmlrpc-c
#./configure
#make
#make install
#ldconfig
#cd ..

#echo "Getting libtorrent + rtorrent files..."
#wget http://downloads.sourceforge.net/mktorrent/mktorrent-1.0.tar.gz &
#wget http://pulsedmedia.com/remote/pkg/rtorrent-0.9.8.tar.gz
#wget http://pulsedmedia.com/remote/pkg/libtorrent-0.13.8.tar.gz


#echo "  uncompressing ..."
#tar zxf libtorrent-0.13.8.tar.gz 
#tar zxf rtorrent-0.9.8.tar.gz
#tar -zxf mktorrent-1.0.tar.gz

#echo "Compiling libtorrent and rtorrent"

#echo "*libTorrent"
#cd libtorrent-0.13.8
#rm -f scripts/{libtool,lt*}.m4
#make clean
#./autogen.sh
#./configure
#make -j12
#make install
#ldconfig
#cd ..


#echo "*rTorrent"
#cd rtorrent-0.9.8
#rm -f scripts/{libtool,lt*}.m4
#make clean
#./autogen.sh
#./configure --with-xmlrpc-c
#make -j12
#make install


#cd ..
#ldconfig

#echo "#### Compiling mktorrent"
#cd ~/compile
#cd mktorrent-1.0
#make
#make install

#echo "#### Installing mediainfo"
#cd /tmp
#mkdir mediainfo
#cd mediainfo
#wget http://downloads.sourceforge.net/zenlib/libzen0_0.4.24-1_amd64.Debian_5.deb
#wget http://downloads.sourceforge.net/mediainfo/libmediainfo0_0.7.53-1_amd64.Debian_5.deb
#wget http://downloads.sourceforge.net/mediainfo/mediainfo_0.7.52-1_amd64.Debian_5.deb
#dpkg -i libzen0_0.4.24-1_amd64.Debian_5.deb
#dpkg -i libmediainfo0_0.7.53-1_amd64.Debian_5.deb
#dpkg -i mediainfo_0.7.52-1_amd64.Debian_5.deb


echo "### Setting up software"
mkdir ~/compile
cd /tmp
rm -rf PMSS*
wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tarball_url/ {print $(NF-1)}' | xargs wget -O PMSS.tar.gz
tar -xzf PMSS.tar.gz
mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1
cd PMSS
bash soft.sh

echo "### Setting up HDD schedulers"
echo "
#Pulsed Media Config
block/sda/queue/scheduler = cfq
block/sdb/queue/scheduler = cfq
block/sdc/queue/scheduler = cfq
block/sdd/queue/scheduler = cfq
block/sde/queue/scheduler = cfq
block/sdf/queue/scheduler = cfq

block/sda/queue/read_ahead_kb = 1024
block/sdb/queue/read_ahead_kb = 1024
block/sdc/queue/read_ahead_kb = 1024
block/sdd/queue/read_ahead_kb = 1024
block/sde/queue/read_ahead_kb = 1024
block/sdf/queue/read_ahead_kb = 1024

net.ipv4.ip_forward = 1
" >> /etc/sysctl.d/1-pmss-defaults.conf

echo "### Setting up basic shell env"
echo "alias ls='ls --color=auto'" >> /root/.bashrc
echo "PATH=\$PATH:/scripts" >> /root/.bashrc

#echo "### Setting md3 & md4 (best quess) reserved blocks to 0%"
#tune2fs -m0 /dev/md3
#tune2fs -m0 /dev/md4

echo "### setting /home permissions"
chmod o-rw /home

echo "### Daemon configurations, quota checkup"
/scripts/util/ftpConfig.php
#/scripts/util/update-step2.php
/scripts/update.php
/scripts/util/setupRootCron.php
/scripts/util/setupSkelPermissions.php

echo "#### Setting up Lighttpd"
#lighty-enable-mod fastcgi auth
#lighty-enable-mod fastcgi-php

#echo "
#auth.backend = \"htpasswd\"
#auth.backend.htpasswd.userfile = \"/etc/lighttpd/.htpasswd\"
#" >> /etc/lighttpd/lighttpd.conf


/scripts/util/quotaFix.php
#/scripts/update.php

#/scripts/util/setupApiKey.php

