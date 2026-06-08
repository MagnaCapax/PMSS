<?php
/** Remote download/install helpers for pinned app artifacts. */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once dirname(__DIR__, 2).'/pathSafety.php';

const PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS = 150;

function pmssPinnedRemoteChecksum(string $path): string
{
    $checksum = @hash_file('sha256', $path);
    return is_string($checksum) ? strtolower($checksum) : '';
}

function pmssPinnedRemoteAmd64ArtifactsSupported(?string $architecture = null): bool
{
    return in_array($architecture ?? php_uname('m'), ['x86_64', 'amd64'], true);
}

/** Run a bounded app version probe without letting daemon-capable binaries wedge updates. */
function pmssAppVersionProbeOutput(string $command, int $timeoutSeconds = PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS): string
{
    $result = pmssCommandCapture($command, max(1, $timeoutSeconds));
    return is_string($result['stdout'] ?? null) ? $result['stdout'] : '';
}

/** Return the first regex capture from bounded app version probes. */
function pmssAppVersionProbeMatch(array $commands, string $pattern, int $capture = 0, int $timeoutSeconds = PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS): ?string
{
    foreach ($commands as $command) {
        if (preg_match($pattern, pmssAppVersionProbeOutput((string) $command, $timeoutSeconds), $match) === 1) return (string) ($match[$capture] ?? $match[0]);
    }
    return null;
}

/** Reject archive basenames that could become shell options or path escapes. */
function pmssPinnedRemoteArchiveComponentIsSafe(string $component): bool
{
    return $component !== ''
        && $component !== '.'
        && $component !== '..'
        && substr($component, 0, 1) !== '-'
        && strpos($component, '/') === false
        && strpos($component, '\\') === false
        && strpos($component, "\0") === false;
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

    $host = parse_url($url, PHP_URL_HOST);
    if (strpos($url, 'https://') !== 0
        || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
        || !is_string($host)
        || $host === ''
    ) {
        logmsg("[WARN] Refusing unsafe remote URL for {$label}");
        return null;
    }

    $tmp = pmssCreatePrivateTempFile($tempPrefix);
    if ($tmp === null) {
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
    $trimmedWorkDir = rtrim($workDir, '/');
    foreach ([$archiveName, $sourceDir] as $component) {
        if (!pmssPinnedRemoteArchiveComponentIsSafe($component)) {
            logmsg("[WARN] Refusing unsafe archive extraction path for {$label}");
            return;
        }
    }
    if ($workDir === ''
        || $trimmedWorkDir === ''
        || !pmssPathTargetIsSafe($trimmedWorkDir, true, false, false)
    ) {
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
            || runStep("Installing {$label}", dpkgCmd('-i '.escapeshellarg($tmp))) === 0;
    } finally {
        @unlink($tmp);
    }
}
