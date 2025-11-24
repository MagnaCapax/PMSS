<?php
# Pulsed Media Seedbox Management Software "PMSS"
# Rclone installer + update

// Runtime version record keeps hosts from redownloading the same archive; the
// pin lives in code so we are not shipping static metadata under /etc.
const RCLONE_VERSION_RECORD = '/etc/seedbox/config/app-versions/rclone';
const RCLONE_DEFAULT_VERSION = '1.69.1';

// Version pinning keeps deployments reproducible; opt-in fetch updates on demand.
[$rcloneVersion, $fetchedLatest] = pmssResolveRcloneVersion();

# Optional info when a newer version is requested
if ($fetchedLatest) {
    echo "Requested latest rclone release: {$rcloneVersion}\n";
}

#Check rclone version
if (file_exists('/usr/bin/rclone')) {
    $rcloneCurrentVersion = `/usr/bin/rclone -V`;
    if (strpos($rcloneCurrentVersion, "rclone v{$rcloneVersion}") === false) {
        unlink('/usr/bin/rclone');    // This forces following code to install rclone .. thus updating it :)
    }
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


/**
 * Resolve which rclone version should be installed.
 */
function pmssResolveRcloneVersion(): array
{
    $pinnedFile = RCLONE_VERSION_RECORD;
    $default = RCLONE_DEFAULT_VERSION;

    $pinned = trim((string)@file_get_contents($pinnedFile));
    if ($pinned !== '' && $pinned[0] === 'v') {
        $pinned = substr($pinned, 1);
    }

    $version = $pinned !== '' ? $pinned : $default;
    $fetched = false;

    if (getenv('PMSS_RCLONE_FETCH_LATEST') === '1') {
        $latest = pmssFetchLatestRcloneVersion();
        if ($latest !== null) {
            $version = $latest;
            $fetched = true;
        }
    }

    pmssPersistRcloneVersion($pinnedFile, $version);
    return [$version, $fetched];
}

/**
 * Try to discover the newest rclone release without breaking when offline.
 */
function pmssFetchLatestRcloneVersion(): ?string
{
    $sources = [
        'https://downloads.rclone.org/version.txt',
        'https://rclone.org/downloads/',
    ];
    foreach ($sources as $url) {
        $payload = @file_get_contents($url);
        if ($payload === false) {
            continue;
        }
        if (preg_match('/v?(\d+\.\d+\.\d+)/', $payload, $match)) {
            return $match[1];
        }
    }
    echo "Warning: Unable to determine latest rclone version, falling back to pinned release.\n";
    return null;
}

/**
 * Store the selected version for reproducible future runs.
 */
function pmssPersistRcloneVersion(string $file, string $version): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($file, $version.PHP_EOL);
}
