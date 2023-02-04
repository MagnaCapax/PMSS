<?php
// Mono installer
// i think this conflicts with sonarr, so currently commented out

#Check Mono version and install the correct one if missing or not installed
/*$monoVersion = shell_exec('mono -V');
if (strpos($monoVersion, ' version 3.8.0 (tarball') === false) {
    echo "## Installing Mono 3.8\n";
    passthru("cd /tmp; wget http://pulsedmedia.com/remote/pkg/mono-3.8.0.tar.bz2; tar -jxf mono-3.8.0.tar.bz2; cd mono-3.8.0; ./configure --prefix=/usr/local; make -j12; make install");
}
*/
if ($debianVersion[0] == 8 &&
    !file_exists('/opt/NzbDrone/NzbDrone.exe') &&
    file_exists('/etc/apt/sources.list.d/sonarr.list')) {

    unlink('/etc/apt/sources.list.d/sonarr.list');
    passthru("apt-get update");
}

passthru('apt-get install mono-complete -y');
