<?php
/** Remote download/install helpers for pinned app artifacts. */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once dirname(__DIR__, 2).'/pathSafety.php';

function pmssPinnedRemoteChecksum(string $path): string
{
    $checksum = @hash_file('sha256', $path);
    return is_string($checksum) ? strtolower($checksum) : '';
}

function pmssPinnedRemoteAmd64ArtifactsSupported(?string $architecture = null): bool
{
    return in_array($architecture ?? php_uname('m'), ['x86_64', 'amd64'], true);
}

function pmssPinnedRemoteArchiveComponentIsSafe(string $value): bool
{
    return $value !== ''
        && $value !== '.'
        && $value !== '..'
        && strpos($value, '/') === false
        && strpos($value, '\\') === false
        && strpos($value, "\0") === false;
}

function pmssPinnedRemoteArchiveWorkDirIsSafe(string $workDir): bool
{
    $trimmed = rtrim($workDir, '/');

    return $workDir !== ''
        && strpos($workDir, '/') === 0
        && strpos($workDir, "\0") === false
        && $trimmed !== ''
        && strpos($trimmed.'/', '/../') === false;
}

/** Validate a binary install destination before crossing into `install(1)`. */
function pmssPinnedRemoteDestinationIsSafe(string $destination): bool
{
    return pmssPathTargetIsSafe($destination, false, true);
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
    if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
        logmsg("[WARN] Refusing invalid SHA-256 pin for {$label}");
        return null;
    }

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

/** Download a verified archive, unpack it in the compile workspace, then run caller steps. */
function pmssRunPinnedRemoteArchiveStep(string $label, string $url, string $expectedSha256, string $archiveName, string $sourceDir, string $description, array $postExtractCommands, string $workDir = '/root/compile'): void
{
    if (!pmssPinnedRemoteArchiveComponentIsSafe($archiveName)
        || !pmssPinnedRemoteArchiveComponentIsSafe($sourceDir)
        || !pmssPinnedRemoteArchiveWorkDirIsSafe($workDir)) {
        logmsg("[WARN] Refusing unsafe archive extraction path for {$label}");
        return;
    }

    $archivePath = pmssFetchPinnedRemoteFile($label, $url, $expectedSha256);
    if ($archivePath === null) {
        return;
    }

    $tarMode = substr($archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf';
    $commands = ['set -e', 'mkdir -p '.escapeshellarg($workDir), 'cd '.escapeshellarg($workDir),
        'rm -rf '.escapeshellarg($sourceDir).' '.escapeshellarg($archiveName),
        'cp '.escapeshellarg($archivePath).' '.escapeshellarg($archiveName), 'tar '.$tarMode.' '.escapeshellarg($archiveName)];
    foreach ($postExtractCommands as $command) {
        $commands[] = (string) $command;
    }

    try {
        runStep($description, implode(' && ', $commands));
    } finally {
        @unlink($archivePath);
    }
}

/** Install a verified remote binary, refreshing only when needed. */
function pmssInstallPinnedRemoteBinary(
    string $label,
    string $url,
    string $expectedSha256,
    string $destination,
    bool $refreshWhenPresent
): void {
    if (!pmssPinnedRemoteDestinationIsSafe($destination)) {
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
