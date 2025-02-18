<?php
# Pulsed Media Seedbox Management Software "PMSS"
# Rclone installer + update

// Define version first
# TODO just get the version # from https://rclone.org/downloads/ ....
$rcloneVersion = '1.69.1';

#Check rclone version
if (file_exists('/usr/bin/rclone')) {
    $rcloneCurrentVersion = `/usr/bin/rclone -V`;
    if (strpos($rcloneCurrentVersion, "rclone v{$rcloneVersion}") == false) unlink('/usr/bin/rclone');    // This forces following code to install rclone .. thus updating it :)
}

#Install rclone
if (!file_exists('/usr/bin/rclone')) {
    // We use random directory so a potential malicious user could not try to pass their binary to be global. Extremely unlikely, and will require already "local" access to even attempt (ie. have non-privileged access already via local user account)
    $randomDirectory = sha1('rclone' . time() . rand(100, 900000));
    mkdir("/tmp/{$randomDirectory}", 0755);
    passthru("cd  /tmp/{$randomDirectory}; wget https://downloads.rclone.org/v{$rcloneVersion}/rclone-v{$rcloneVersion}-linux-amd64.zip; unzip rclone-v{$rcloneVersion}-linux-amd64.zip; cd rclone-v{$rcloneVersion}-linux-amd64; cp rclone /usr/bin/; chown root:root /usr/bin/rclone; chmod 755 /usr/bin/rclone; mkdir -p /usr/local/share/man/man1; cp rclone.1 /usr/local/share/man/man1/; mandb;");
}

#Fix for rclone install path / paths lacking. Not included in above because in many places needs to fixed
if (file_exists('/usr/sbin/rclone') &&
    !file_exists('/usr/bin/rclone') )   passthru('mv /usr/sbin/rclone /usr/bin/rclone');

