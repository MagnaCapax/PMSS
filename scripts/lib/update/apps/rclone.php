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
// SHA-256 of rclone-v1.69.1-linux-amd64.zip from the release's signed SHA256SUMS.
const PMSS_RCLONE_VERSION = '1.69.1';
const PMSS_RCLONE_SHA256 = '231841f8d8029ae6cfca932b601b3b50d0e2c3c2cb9da3166293f1c3eae7d79c';

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

function pmssRcloneArchiveName(string $version): string
{
    return 'rclone-v'.$version.'-linux-amd64.zip';
}

/** Find one archive's SHA-256 in an rclone SHA256SUMS listing (clearsigned or plain). */
function pmssRcloneChecksumFromSums(string $sums, string $archive): ?string
{
    foreach (preg_split('/\r?\n/', $sums) ?: [] as $line) {
        if (preg_match('/\A([a-fA-F0-9]{64}) [ *](\S+)\z/', trim($line), $match) === 1 && $match[2] === $archive) {
            return strtolower($match[1]);
        }
    }

    return null;
}

/** The latest release has no pin; take its checksum from that release's SHA256SUMS over HTTPS. */
function pmssRcloneLatestChecksumFetch(string $version): ?string
{
    $payload = @file_get_contents('https://downloads.rclone.org/v'.$version.'/SHA256SUMS', false, null, 0, 65536);
    return is_string($payload) ? pmssRcloneChecksumFromSums($payload, pmssRcloneArchiveName($version)) : null;
}

/** @return array{0:string,1:string} version and archive SHA-256; latest only when both resolve. */
function pmssRcloneTargetRelease(bool $fetchLatest, ?callable $latestVersion = null, ?callable $latestChecksum = null): array
{
    $pinned = [PMSS_RCLONE_VERSION, PMSS_RCLONE_SHA256];
    if (!$fetchLatest) return $pinned;

    if ($latestVersion === null) {
        $latestVersion = static function (): ?string {
            return pmssRcloneLatestVersionFetch(['https://downloads.rclone.org/version.txt', 'https://rclone.org/downloads/']);
        };
    }
    $latestChecksum = $latestChecksum ?: 'pmssRcloneLatestChecksumFetch';
    $version = $latestVersion();
    if ($version === null) {
        echo "Warning: Unable to determine latest rclone version, falling back to pinned release.\n";
        return $pinned;
    }
    echo "Requested latest rclone release: {$version}\n";
    if ($version === PMSS_RCLONE_VERSION) return $pinned;
    $sha256 = $latestChecksum($version);
    if ($sha256 === null) {
        echo "Warning: Unable to read the SHA-256 for rclone {$version}, falling back to pinned release.\n";
        return $pinned;
    }
    return [$version, $sha256];
}

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
        $url = 'https://downloads.rclone.org/v'.$version.'/'.pmssRcloneArchiveName($version);
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
        [$rcloneVersion, $rcloneSha256] = pmssRcloneTargetRelease(pmssEnvFlagEnabled('PMSS_RCLONE_FETCH_LATEST'));
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
