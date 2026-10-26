<?php
/** Remote download/install helpers for pinned app artifacts. */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once __DIR__.'/remoteBinary/versionProbe.php';
require_once __DIR__.'/remoteBinary/artifact.php';
require_once __DIR__.'/remoteBinary/archive.php';

function pmssPinnedRemoteAmd64ArtifactsSupported(?string $architecture = null): bool
{
    return in_array($architecture ?? php_uname('m'), ['x86_64', 'amd64'], true);
}

/** Keep the pinned amd64 installer skip message consistent across apps. */
function pmssPinnedRemoteAmd64OrSkip(string $label, string $action = 'bootstrap'): bool
{
    if (pmssPinnedRemoteAmd64ArtifactsSupported()) return true;
    logmsg('[SKIP] '.$label.' '.$action.' skipped on unsupported architecture: '.php_uname('m'));
    return false;
}

/** Read a pinned install marker only for a regular binary and a non-symlink marker. */
function pmssPinnedRemoteVersionMatches(string $binary, string $marker, string $version): bool
{
    // Warning suppression cannot turn invalid path arguments into a failed read.
    if ($binary === '' || $marker === ''
        || strpos($binary, "\0") !== false || strpos($marker, "\0") !== false) return false;
    return is_file($binary) && !is_link($marker) && trim((string) @file_get_contents($marker)) === $version;
}

/** Publish a version only through a safe regular marker path after installation. */
function pmssPinnedRemoteVersionMarkerWrite(string $marker, string $version): bool { return pmssPathTargetIsSafe($marker, false, true) && pmssFileWriteComplete($marker, $version."\n"); }

/** Remove a stale managed binary only when its path is safe and removal succeeds. */
function pmssPinnedRemoteStaleBinaryRemove(string $path, string $label): bool
{
    if (!pmssPathTargetIsSafe($path, false, true)) {
        logmsg("[WARN] Refusing unsafe {$label} binary path");
        return false;
    }

    if (pmssPathExistsOrLink($path) && !@unlink($path)) {
        logmsg("[WARN] Unable to remove stale {$label} binary; leaving existing install in place");
        return false;
    }

    return true;
}

/** Prepare a pinned binary only when its installed version does not match. */
function pmssPinnedRemoteBinaryPrepareInstall(string $path, array $commands, string $pattern, string $label): bool
{
    return (!file_exists($path) || pmssAppVersionProbeMatch($commands, $pattern) === null)
        && pmssPinnedRemoteStaleBinaryRemove($path, $label);
}

/** Install a verified remote binary, refreshing only when needed. */
function pmssInstallPinnedRemoteBinary(
    string $label,
    string $url,
    string $expectedSha256,
    string $destination,
    bool $refreshWhenPresent
): void {
    if (!pmssPathTargetIsSafe($destination, false, true)) {
        logmsg("[WARN] Refusing unsafe install destination for {$label}: {$destination}");
        return;
    }

    $expectedSha256 = strtolower($expectedSha256);
    if (is_file($destination)) {
        if (!$refreshWhenPresent) {
            return;
        }
        if (pmssPinnedRemoteChecksum($destination) === $expectedSha256) {
            logmsg("[SKIP] {$label} already matches pinned checksum; skipping refresh");
            return;
        }
    }

    pmssPinnedRemoteArtifactTempFileUse($label, $url, $expectedSha256, static function (string $tmp) use ($label, $destination, $expectedSha256): void {
        $rc = runStep("Installing {$label}", pmssBuildCommand('install', ['-m', '0755', $tmp, $destination]));
        if ($rc !== 0) {
            logMessage("[WARN] {$label} install command failed with rc={$rc}; destination checksum not trusted");
            return;
        }

        $actualSha = pmssPinnedRemoteChecksum($destination);
        if ($actualSha !== $expectedSha256) {
            logMessage("[WARN] {$label} installed checksum mismatch; expected {$expectedSha256}, got ".($actualSha ?: 'unknown'));
        }
    });
}

/** Install a verified Debian package; dry-run still reports success. */
function pmssInstallPinnedRemoteDebPackage(string $label, string $url, string $expectedSha256): bool
{
    return pmssPinnedRemoteArtifactTempFileUse(
        $label,
        $url,
        $expectedSha256,
        static function (string $tmp) use ($label): bool {
            return pmssEnvFlagEnabled('PMSS_DRY_RUN')
                || runStep("Installing {$label}", dpkgCmd('-i '.escapeshellarg($tmp))) === 0;
        },
        true,
        ['tempPrefix' => 'pmss-remote-deb-', 'downloadDescription' => "Downloading {$label} package", 'artifactLabel' => ' package']
    ) === true;
}
