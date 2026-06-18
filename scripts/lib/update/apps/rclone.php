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

function pmssRcloneVersionIsSafe(string $version): bool
{
    return preg_match('/\A\d+\.\d+\.\d+\z/', $version) === 1;
}

function pmssRcloneLatestVersionFetch(array $urls): ?string
{
    foreach ($urls as $url) {
        $payload = @file_get_contents((string) $url, false, null, 0, 65536);
        if (is_string($payload) && preg_match('/v?(\d+\.\d+\.\d+)/', $payload, $match) === 1) {
            return $match[1];
        }
    }

    return null;
}

function pmssRcloneInstallCommand(string $version, string $workDir): string
{
    $archive = 'rclone-v'.$version.'-linux-amd64.zip';
    $sourceDir = 'rclone-v'.$version.'-linux-amd64';
    $url = 'https://downloads.rclone.org/v'.$version.'/'.$archive;
    $commands = [
        'set -e',
        'cd '.escapeshellarg($workDir),
        pmssBuildCommand('wget', [$url]),
        pmssBuildCommand('unzip', [$archive]),
        'cd '.escapeshellarg($sourceDir),
        pmssBuildCommand('install', ['-m', '0755', '-o', 'root', '-g', 'root', 'rclone', '/usr/bin/rclone']),
        pmssBuildCommand('mkdir', ['-p', '/usr/local/share/man/man1']),
        pmssBuildCommand('install', ['-m', '0644', 'rclone.1', '/usr/local/share/man/man1/rclone.1']),
        pmssBuildCommand('mandb'),
    ];

    return implode(' && ', $commands);
}

function pmssRcloneInstallFromZip(string $version): void
{
    if (!pmssRcloneVersionIsSafe($version)) {
        logmsg("[WARN] Refusing unsafe rclone version: {$version}");
        return;
    }

    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        runStep('Installing rclone '.$version, pmssRcloneInstallCommand($version, '/tmp/pmss-rclone-dry-run'));
        return;
    }

    $workDir = pmssCreatePrivateTempDir('pmss-rclone-');
    if ($workDir === null) {
        logmsg('[WARN] Unable to create private rclone installer workspace');
        return;
    }

    try {
        runStep('Installing rclone '.$version, pmssRcloneInstallCommand($version, $workDir));
    } finally {
        pmssRemovePrivateTempDir($workDir, 'pmss-rclone-', 'Cleaning rclone installer workspace', 'logmsg');
    }
}

// Version pinning keeps deployments reproducible; opt-in fetch updates on demand.
$rcloneVersion = '1.69.1';
$fetchedLatest = false;
if (pmssEnvFlagEnabled('PMSS_RCLONE_FETCH_LATEST')) {
    $latestVersion = pmssRcloneLatestVersionFetch(['https://downloads.rclone.org/version.txt', 'https://rclone.org/downloads/']);
    if ($latestVersion !== null) {
        $rcloneVersion = $latestVersion;
        $fetchedLatest = true;
    }
    if (!$fetchedLatest) {
        echo "Warning: Unable to determine latest rclone version, falling back to pinned release.\n";
    }
}

# Optional info when a newer version is requested
if ($fetchedLatest) {
    echo "Requested latest rclone release: {$rcloneVersion}\n";
}
$currentRclone = file_exists('/usr/bin/rclone')
    ? pmssAppVersionProbeMatch(['/usr/bin/rclone version 2>/dev/null', '/usr/bin/rclone -V 2>/dev/null'], '/rclone v?(\d+\.\d+\.\d+)/i', 1)
    : null;
if ($currentRclone !== null && $currentRclone !== $rcloneVersion) {
    if (!pmssPathTargetIsSafe('/usr/bin/rclone', false, true) || !@unlink('/usr/bin/rclone')) {
        logmsg('[WARN] Unable to remove mismatched rclone binary; leaving existing install in place');
    }
}

#Install rclone
if (!file_exists('/usr/bin/rclone')) {
    pmssRcloneInstallFromZip($rcloneVersion);
}

#Fix for rclone install path / paths lacking. Not included in above because in many places needs to fixed
if (file_exists('/usr/sbin/rclone') &&
    !file_exists('/usr/bin/rclone') ) {
    if (!pmssPathTargetIsSafe('/usr/sbin/rclone', false, true) || !pmssPathTargetIsSafe('/usr/bin/rclone', false, true)) {
        logmsg('[WARN] Refusing unsafe legacy rclone path repair');
    } else {
        runStep('Moving legacy rclone binary into /usr/bin', pmssBuildCommand('mv', ['/usr/sbin/rclone', '/usr/bin/rclone']));
    }
}
