<?php
/**
 * Update app installer: rclone.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
# Pulsed Media Seedbox Management Software "PMSS"
# Rclone installer + update

require_once __DIR__.'/remoteBinary.php';

// Version pinning keeps deployments reproducible; opt-in fetch updates on demand.
$rcloneVersion = '1.69.1';
$fetchedLatest = false;
if (getenv('PMSS_RCLONE_FETCH_LATEST') === '1') {
    foreach (['https://downloads.rclone.org/version.txt', 'https://rclone.org/downloads/'] as $url) {
        $payload = @file_get_contents($url);
        if ($payload !== false && preg_match('/v?(\d+\.\d+\.\d+)/', $payload, $match)) {
            $rcloneVersion = $match[1];
            $fetchedLatest = true;
            break;
        }
    }
    if (!$fetchedLatest) {
        echo "Warning: Unable to determine latest rclone version, falling back to pinned release.\n";
    }
}

# Optional info when a newer version is requested
if ($fetchedLatest) {
    echo "Requested latest rclone release: {$rcloneVersion}\n";
}
$currentRclone = null;
if (file_exists('/usr/bin/rclone')) {
    foreach (['/usr/bin/rclone version 2>/dev/null', '/usr/bin/rclone -V 2>/dev/null'] as $command) {
        if (preg_match('/rclone v?(\d+\.\d+\.\d+)/i', pmssAppVersionProbeOutput($command), $match)) {
            $currentRclone = $match[1];
            break;
        }
    }
}
if ($currentRclone !== null && $currentRclone !== $rcloneVersion) {
    unlink('/usr/bin/rclone');    // This forces following code to install rclone .. thus updating it :)
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
