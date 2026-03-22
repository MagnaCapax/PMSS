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
    if (strpos($url, 'https://') !== 0) {
        logmsg("[WARN] Refusing non-HTTPS URL for {$label}: {$url}");
        return;
    }

    if (is_file($destination)) {
        if (!$refreshWhenPresent) {
            return;
        }
        $installedSha = @hash_file('sha256', $destination);
        if (is_string($installedSha) && strtolower($installedSha) === strtolower($expectedSha256)) {
            logmsg("[SKIP] {$label} already matches pinned checksum; skipping refresh");
            return;
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pmss-remote-bin-');
    if ($tmp === false || $tmp === '') {
        logmsg("[WARN] Unable to create temp file for {$label} download"); return;
    }

    try {
        if (runStep("Downloading {$label}", pmssBuildCommand('wget', ['-q', '-O', $tmp, $url])) !== 0) {
            return;
        }

        if (getenv('PMSS_DRY_RUN') === '1') { return; }

        $actualSha = @hash_file('sha256', $tmp);
        if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($expectedSha256)) {
            logmsg("[WARN] {$label} checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')'); return;
        }

        runStep("Installing {$label}", pmssBuildCommand('install', ['-m', '0755', $tmp, $destination]));
    } finally {
        @unlink($tmp);
    }
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
    if (strpos($url, 'https://') !== 0) {
        logmsg("[WARN] Refusing non-HTTPS URL for {$label}: {$url}");
        return false;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pmss-remote-deb-');
    if ($tmp === false || $tmp === '') {
        logmsg("[WARN] Unable to create temp file for {$label} package download"); return false;
    }

    try {
        if (runStep("Downloading {$label} package", pmssBuildCommand('wget', ['-q', '-O', $tmp, $url])) !== 0) {
            return false;
        }

        if (getenv('PMSS_DRY_RUN') === '1') { return true; }

        $actualSha = @hash_file('sha256', $tmp);
        if (!is_string($actualSha) || strtolower($actualSha) !== strtolower($expectedSha256)) {
            logmsg("[WARN] {$label} package checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')'); return false;
        }

        return runStep("Installing {$label}", pmssBuildCommand('dpkg', ['-i', $tmp])) === 0;
    } finally {
        @unlink($tmp);
    }
}
