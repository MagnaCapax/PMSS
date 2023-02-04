<?php
// Sonarr installer



if (!file_exists('/etc/apt/sources.list.d/sonarr.list')) {
	#Following is for sonarr
	passthru('apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493');
	passthru('echo "deb http://apt.sonarr.tv/ master main" | tee /etc/apt/sources.list.d/sonarr.list');
}

if (!file_exists('/opt/NzbDrone/NzbDrone.exe')) {
    echo "## Installing Sonarr / Nzbdrone\n";
    passthru('apt-get update; apt-get install nzbdrone -y');
}

