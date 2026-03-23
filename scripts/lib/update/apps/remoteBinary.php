<?php
/** Remote download/install helpers for pinned app artifacts. */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

function pmssPinnedRemoteChecksum(string $path): string
{
    $checksum = @hash_file('sha256', $path);
    return is_string($checksum) ? strtolower($checksum) : '';
}

// Download a pinned artifact to a temp file; caller owns cleanup.
function pmssDownloadPinnedRemoteTempFile(
    string $label,
    string $url,
    string $expectedSha256,
    string $tempPrefix,
    string $downloadDescription,
    string $artifactLabel = ''
): ?string {
    $expectedSha256 = strtolower($expectedSha256);
    if (strpos($url, 'https://') !== 0) {
        logmsg("[WARN] Refusing non-HTTPS URL for {$label}: {$url}");
        return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), $tempPrefix);
    if ($tmp === false || $tmp === '') {
        logmsg("[WARN] Unable to create temp file for {$label} download");
        return null;
    }

    if (runStep($downloadDescription, pmssBuildCommand('wget', ['-q', '-O', $tmp, $url])) !== 0) {
        @unlink($tmp);
        return null;
    }
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        return $tmp;
    }

    $actualSha = pmssPinnedRemoteChecksum($tmp);
    if ($actualSha === '' || $actualSha !== $expectedSha256) {
        logmsg("[WARN] {$label}{$artifactLabel} checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')');
        @unlink($tmp);
        return null;
    }

    return $tmp;
}

/** Fetch a verified remote file; returns null in dry-run mode. */
function pmssFetchPinnedRemoteFile(string $label, string $url, string $expectedSha256): ?string
{
    $tmp = pmssDownloadPinnedRemoteTempFile(
        $label,
        $url,
        $expectedSha256,
        'pmss-remote-bin-',
        "Downloading {$label}"
    );
    if ($tmp === null) {
        return null;
    }
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        @unlink($tmp);
        return null;
    }

    return $tmp;
}

/** Install a verified remote binary, refreshing only when needed. */
function pmssInstallPinnedRemoteBinary(
    string $label,
    string $url,
    string $expectedSha256,
    string $destination,
    bool $refreshWhenPresent
): void {
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

    $tmp = pmssFetchPinnedRemoteFile($label, $url, $expectedSha256);
    if ($tmp === null) {
        return;
    }

    try {
        runStep("Installing {$label}", pmssBuildCommand('install', ['-m', '0755', $tmp, $destination]));
    } finally {
        @unlink($tmp);
    }
}

/** Install a verified Debian package; dry-run still reports success. */
function pmssInstallPinnedRemoteDebPackage(string $label, string $url, string $expectedSha256): bool
{
    $tmp = pmssDownloadPinnedRemoteTempFile(
        $label,
        $url,
        $expectedSha256,
        'pmss-remote-deb-',
        "Downloading {$label} package",
        ' package'
    );
    if ($tmp === null) {
        return false;
    }

    try {
        return pmssEnvFlagEnabled('PMSS_DRY_RUN')
            || runStep("Installing {$label}", pmssBuildCommand('dpkg', ['-i', $tmp])) === 0;
    } finally {
        @unlink($tmp);
    }
}
