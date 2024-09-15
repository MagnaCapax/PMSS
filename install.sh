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


DEFAULT_REPOSITORY="https://github.com/MagnaCapax/PMSS"
date=
type=
url=
repository=
branch=

get_dpkg_selections(){
    local distro_name=$1;
    local distro_version=$2;

    # TODO: Check different distro versions for right packages
    
    echo $(wget  -nv -q -O - https://raw.githubusercontent.com/MagnaCapax/PMSS/main/packages.$distro_name.$distro_version) 

}

get_distro_name() {
    eval $(
        source /etc/os-release;
        echo name=$ID;
        )
    echo $name;
}

get_distro_version() {
    eval $(
        source /etc/os-release;
        echo version=$VERSION_ID;
        )
    echo $version;
}

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
# update dpkg available packages for dpkg --set-selections
dpkg --admindir=/var/lib/dpkg --update-avail <(apt-cache dumpavail)

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

# Package selections, and remove some defaults etc.
dpkg --clear-selections
$distro_name=$(get_distro_name)
$distro_version=$(get_distro_version)

case $distro_name in
    debian)
        dpkg --set-selections < $(get_dpkg_selections $distro_name $distro_version)
        ;;
    ubuntu)
        echo "Ubuntu is not supported yet."
        exit 1
        ;;
    *)
        echo "Unsupported distribution."
        exit 1
        ;;
esac

apt-get delect-upgrade


# Script installs from release by default and uses a specific git branch as the source if given string of "git/branch" format
echo "### Setting up software"
mkdir ~/compile
cd /tmp
rm -rf PMSS*
echo 
parse_version_string $1

if [ "$type" = "git" ]; then
    git clone $repository PMSS;
    git checkout "$branch";
    mv PMSS/* /;
    SOURCE="$type/$repository:$branch"
    VERSION=$(date)
else
    VERSION=$(wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tag_name/ {print $(NF-1)}')
    wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/${VERSION}" -O PMSS.tar.gz;
    mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1;
    mv PMSS/* /;
    
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
/scripts/update.php
/scripts/util/setupRootCron.php
/scripts/util/setupSkelPermissions.php
/scripts/util/quotaFix.php
/scripts/util/ftpConfig.php

#TODO Ancient API, but it may yet prove to be useful so not quite yet removed -AU
#/scripts/util/setupApiKey.php

