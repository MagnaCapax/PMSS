#!/bin/bash
#Pulsed Media Seedbox Management Software package AKA Pulsed Media Software Stack "PMSS"
#
# LICENSE
# This work is licensed under the Creative Commons Attribution-ShareAlike 3.0 Unported License. 
# To view a copy of this license, visit http://creativecommons.org/licenses/by-sa/3.0/ or send a letter to Creative Commons, 171 Second Street, Suite 300, San Francisco, California, 94105, USA.

# Copyright 2010-2024 Magna Capax Finland Oy
#
# USE AT YOUR OWN RISK! ABSOLUTELY NO GUARANTEES GIVEN!
# Pulsed Media does not take any responsibility over usage of this package, and will not be responsible for any damages, injuries, losses, or anything else for that matter due to the
# usage of this package.
#
# Please note: Install this on a fresh, minimal install Debian 64bit.
#
# For help, see http://wiki.pulsedmedia.com
# Github: https://github.com/MagnaCapax/PMSS

# Usage for special branch: bash ./install.sh "git/http://github.com/MagnaCapax/PMSS:update2-distro-support:2023-07-22"

DEFAULT_REPOSITORY="https://github.com/MagnaCapax/PMSS"
date=
type=
url=
repository=
branch=

parse_version_string() {
    local input_string="$1"

    if [[ $input_string =~ (^git|^release)\/(.*)[:]?([0-9]{4}-[0-9]{2}-[0-9]{2}([ ]?[0-9]{2}[:][0-9]{2})?)$ ]]; then
        type="${BASH_REMATCH[1]}"
        url="${BASH_REMATCH[2]}"
        date="${BASH_REMATCH[3]}"
        echo "Type: $type"
        echo "URL: $url"
        echo "Date: $date"
        if [[ $url =~ (.*[^:])[:](.*[^:])[:]?$ ]]; then
            repository="${BASH_REMATCH[1]}"
            branch="${BASH_REMATCH[2]}"
            echo "Repository: $repository"
            echo "Branch: $branch"

        elif [[ $url =~ (^main)[:]$ ]]; then
            repository=$DEFAULT_REPOSITORY
            branch="${BASH_REMATCH[1]}"
            echo "Repository: $repository"
            echo "Branch: $branch"
        else
            echo "Url doesn't match, using defaults"
            repository=$DEFAULT_REPOSITORY
            branch="main"
        fi
    else
        echo "Invalid input format."
    fi
}

export DEBIAN_FRONTEND=noninteractive
apt update;
# Perform the full-upgrade
apt-get full-upgrade -yqq


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

## #TODO: Update to dselections / dpkg set sel, automate
# TODO this whole follow up section is yucky
# Package selections, and remove some defaults etc.


apt-get remove samba-common exim4-base exim4 netcat netcat-traditonal netcat6 -yq


apt-get install libncurses5-dev less -yq
apt-get install libxmlrpc-c++4-dev libxmlrpc-c++4 -yq    #Not found on deb8
apt-get install libxmlrpc-c++8v5 -yq
apt-get install automake autogen build-essential libwww-curl-perl libcurl4-openssl-dev libsigc++-2.0-dev libwww-perl sysfsutils libcppunit-dev -yq
apt-get install gcc g++ gettext glib-networking libglib2.0-dev libfuse-dev apt-transport-https -yq

apt-get install php php-cli php-geoip php-gd php-apcu php-cgi php-sqlite3 php-common php-xmlrpc php-curl -yq


apt-get install psmisc rsync subversion mktorrent -yq
#apt-get -t testing install mktorrent rsync -y

apt-get install libncurses5 libncurses5-dev  links elinks lynx sudo -yq
apt-get install pkg-config make openssl -yq

#From update-step2 l:72, remove them from there circa 03/2016
apt-get install znc znc-perl znc-python znc-tcl -yq
apt-get install gcc g++ gettext python-cheetah curl fuse glib-networking libglib2.0-dev libfuse-dev apt-transport-https -yq  #Everythin installed already on deb8
apt-get install links elinks lynx ethtool p7zip-full smartmontools -yq   #all but smart + p7zip already installed...
apt-get install flac lame lame-doc mp3diags lftp -yq
#Following are for autodl-irssi
apt-get install libarchive-zip-perl libnet-ssleay-perl libhtml-parser-perl libxml-libxml-perl libjson-perl libjson-xs-perl libxml-libxslt-perl  -yq

apt-get install libssl-dev libssl1.1 mediainfo libmediainfo0v5 -yq  ##TODO Yuck distro version dependant

apt-get install git -yq
apt-get install php-cli php-cgi iptables curl libssl-dev python3-pip cgroup-tools -yq
touch /usr/share/pyload ## XXX: This is a hack to disable pyload-cli install

# Script installs from release by default and uses a specific git branch as the source if given string of "git/branch" format
echo "### Setting up software"
mkdir ~/compile
cd /tmp
rm -rf PMSS*
echo 
parse_version_string $1

if [ "$type" = "git" ]; then
    git clone $repository PMSS;
    ( cd PMSS; git checkout "$branch"; )
    rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /
    rm -rf PMSS
    SOURCE="$type/$repository:$branch"
    VERSION=$(date)
else
    VERSION=$(wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tag_name/ {print $(NF-1)}')
    wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/${VERSION}" -O PMSS.tar.gz;
    mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1;
    rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /
    rm -rf PMSS
    SOURCE="release"
fi



mkdir -p /etc/seedbox/config/
echo "$SOURCE $VERSION" > /etc/seedbox/config/version

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
/scripts/update.php "$1"
/scripts/util/setupRootCron.php
/scripts/util/setupSkelPermissions.php
/scripts/util/quotaFix.php
/scripts/util/ftpConfig.php

#TODO Ancient API, but it may yet prove to be useful so not quite yet removed -AU
#/scripts/util/setupApiKey.php

