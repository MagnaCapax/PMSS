<?php
/**
 * Remote binary download/install helpers for app installers.
 *
 * Keeps remote fetches deterministic and auditable:
 * - Enforces HTTPS.
 * - Verifies SHA256 before install.
 * - Uses runStep() so PMSS_DRY_RUN is honoured and steps are profiled/logged.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

/**
 * Fetch and install a pinned remote binary with SHA256 verification.
 *
 * Downloads to a temp file first; only replaces the destination after the
 * checksum matches.
 *
 * @param string $label Human-readable description used in logs.
 * @param string $url HTTPS URL to fetch.
 * @param string $expectedSha256 Expected SHA256 in hex.
 * @param string $destination Install destination path.
 * @param bool $refreshWhenPresent When true, re-fetch if destination exists but checksum mismatches.
 */
function pmssInstallPinnedRemoteBinary(
    string $label,
    string $url,
    string $expectedSha256,
    string $destination,
    bool $refreshWhenPresent
): void {
    $dryRun = getenv('PMSS_DRY_RUN') === '1';
    $log = 'logmsg';

    if (strpos($url, 'https://') !== 0) {
        $log("[WARN] Refusing non-HTTPS URL for {$label}: {$url}");
        return;
    }

    if (is_file($destination) && !$refreshWhenPresent) {
        return;
    }

    if (is_file($destination) && $refreshWhenPresent) {
        $installedSha = @hash_file('sha256', $destination);
        if (is_string($installedSha) && strtolower($installedSha) === strtolower($expectedSha256)) {
            $log("[SKIP] {$label} already matches pinned checksum; skipping refresh");
            return;
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pmss-remote-bin-');
    if ($tmp === false || $tmp === '') {
        $log("[WARN] Unable to create temp file for {$label} download");
        return;
    }

    $downloadCmd = pmssBuildCommand('wget', ['-q', '-O', $tmp, $url]);
    $rc = runStep("Downloading {$label}", $downloadCmd);
    if ($rc !== 0) {
        @unlink($tmp);
        return;
    }

    if ($dryRun) {
        @unlink($tmp);
        return;
    }

    $actualSha = @hash_file('sha256', $tmp);
    if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($expectedSha256)) {
        $log("[WARN] {$label} checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')');
        @unlink($tmp);
        return;
    }

    $installCmd = pmssBuildCommand('install', ['-m', '0755', $tmp, $destination]);
    runStep("Installing {$label}", $installCmd);
    @unlink($tmp);
}

/**
 * Fetch and install a pinned Debian package with SHA256 verification.
 *
 * The package is downloaded to a temporary file and installed with `dpkg -i`
 * only after checksum verification succeeds.
 */
function pmssInstallPinnedRemoteDebPackage(
    string $label,
    string $url,
    string $expectedSha256
): bool {
    $dryRun = getenv('PMSS_DRY_RUN') === '1';
    $log = 'logmsg';

    if (strpos($url, 'https://') !== 0) {
        $log("[WARN] Refusing non-HTTPS URL for {$label}: {$url}");
        return false;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pmss-remote-deb-');
    if ($tmp === false || $tmp === '') {
        $log("[WARN] Unable to create temp file for {$label} package download");
        return false;
    }

    $downloadCmd = pmssBuildCommand('wget', ['-q', '-O', $tmp, $url]);
    $downloadRc = runStep("Downloading {$label} package", $downloadCmd);
    if ($downloadRc !== 0) {
        @unlink($tmp);
        return false;
    }

    if ($dryRun) {
        @unlink($tmp);
        return true;
    }

    $actualSha = @hash_file('sha256', $tmp);
    if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($expectedSha256)) {
        $log("[WARN] {$label} package checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')');
        @unlink($tmp);
        return false;
    }

    $installCmd = pmssBuildCommand('dpkg', ['-i', $tmp]);
    $installRc = runStep("Installing {$label}", $installCmd);
    @unlink($tmp);

    return $installRc === 0;
}
