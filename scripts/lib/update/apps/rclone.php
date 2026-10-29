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

// Version bumps change the pin in a reviewed commit; there is no unpinned download path.
// SHA-256 of rclone-v1.69.1-linux-amd64.zip from the release's signed SHA256SUMS.
const PMSS_RCLONE_VERSION = '1.69.1';
const PMSS_RCLONE_SHA256 = '231841f8d8029ae6cfca932b601b3b50d0e2c3c2cb9da3166293f1c3eae7d79c';

function pmssRcloneInstallCommand(string $version, string $archivePath, string $workDir): string
{
    $sourceDir = 'rclone-v'.$version.'-linux-amd64';
    $commands = [
        'set -e',
        'cd '.escapeshellarg($workDir),
        pmssBuildCommand('unzip', ['-q', $archivePath]),
        'cd '.escapeshellarg($sourceDir),
        pmssBuildCommand('install', ['-m', '0755', '-o', 'root', '-g', 'root', 'rclone', '/usr/bin/rclone']),
        pmssBuildCommand('mkdir', ['-p', '/usr/local/share/man/man1']),
        pmssBuildCommand('install', ['-m', '0644', 'rclone.1', '/usr/local/share/man/man1/rclone.1']),
        pmssBuildCommand('mandb'),
    ];

    return implode(' && ', $commands);
}

function pmssRcloneInstallFromZip(string $version, string $sha256): void
{
    if (preg_match('/\A\d+\.\d+\.\d+\z/', $version) !== 1) {
        logmsg("[WARN] Refusing unsafe rclone version: {$version}");
        return;
    }

    $dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
    $workDir = $dryRun ? '/tmp/pmss-rclone-dry-run' : pmssCreatePrivateTempDir('pmss-rclone-');
    if ($workDir === null) {
        logmsg('[WARN] Unable to create private rclone installer workspace');
        return;
    }

    try {
        $url = 'https://downloads.rclone.org/v'.$version.'/rclone-v'.$version.'-linux-amd64.zip';
        pmssPinnedRemoteArtifactTempFileUse('rclone '.$version, $url, $sha256, static function (string $archivePath) use ($version, $workDir): bool {
            return runStep('Installing rclone '.$version, pmssRcloneInstallCommand($version, $archivePath, $workDir)) === 0;
        }, true);
    } finally {
        if (!$dryRun) {
            pmssRemovePrivateTempDir($workDir, 'pmss-rclone-', 'Cleaning rclone installer workspace', 'logmsg');
        }
    }
}

function pmssRcloneUpdate(): void
{
    // Upstream ships per-architecture zips; only the amd64 one is fetched and verified here.
    if (pmssPinnedRemoteAmd64OrSkip('rclone', 'install')) {
        if (pmssEnvFlagEnabled('PMSS_RCLONE_FETCH_LATEST')) {
            logmsg('[INFO] PMSS_RCLONE_FETCH_LATEST is no longer supported; using the pinned rclone '.PMSS_RCLONE_VERSION);
        }
        $rcloneVersion = PMSS_RCLONE_VERSION;
        $rcloneSha256 = PMSS_RCLONE_SHA256;
        $currentRclone = file_exists('/usr/bin/rclone')
            ? pmssAppVersionProbeMatch(['/usr/bin/rclone version 2>/dev/null', '/usr/bin/rclone -V 2>/dev/null'], '/rclone v?(\d+\.\d+\.\d+)/i', 1)
            : null;
        $mismatched = $currentRclone !== null && $currentRclone !== $rcloneVersion;
        if ($mismatched && !pmssPathTargetIsSafe('/usr/bin/rclone', false, true)) {
            logmsg('[WARN] Unable to replace mismatched rclone binary; leaving existing install in place');
        } elseif ($mismatched || !file_exists('/usr/bin/rclone')) {
            // install(1) replaces the old binary only after the zip passed its checksum,
            // so a failed or refused download leaves the current rclone in place.
            pmssRcloneInstallFromZip($rcloneVersion, $rcloneSha256);
        }
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
}

if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) pmssRcloneUpdate();
