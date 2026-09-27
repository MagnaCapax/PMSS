<?php
/** Remote download/install helpers for pinned app artifacts. */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once __DIR__.'/remoteBinary/versionProbe.php';
require_once __DIR__.'/remoteBinary/artifact.php';

function pmssPinnedRemoteAmd64ArtifactsSupported(?string $architecture = null): bool
{
    return in_array($architecture ?? php_uname('m'), ['x86_64', 'amd64'], true);
}

/** Remove a stale managed binary only when its path is safe and removal succeeds. */
function pmssPinnedRemoteStaleBinaryRemove(string $path, string $label): bool
{
    if (!pmssPathTargetIsSafe($path, false, true)) {
        logmsg("[WARN] Refusing unsafe {$label} binary path");
        return false;
    }

    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    if (!@unlink($path)) {
        logmsg("[WARN] Unable to remove stale {$label} binary; leaving existing install in place");
        return false;
    }

    return true;
}

/** Reject archive basenames that could become shell options, path escapes, or log controls. */
function pmssPinnedRemoteArchiveComponentIsSafe(string $component): bool
{
    return $component !== ''
        && $component !== '.'
        && $component !== '..'
        && substr($component, 0, 1) !== '-'
        && strpos($component, '/') === false
        && strpos($component, '\\') === false
        && preg_match('/[\x00-\x1F\x7F]/', $component) !== 1;
}

/** Reject post-extract shell fragments that would break the generated command chain. */
function pmssPinnedRemoteArchivePostCommandIsSafe(string $command): bool
{
    return trim($command) !== ''
        && strpos($command, "\0") === false
        && preg_match('/[\r\n]/', $command) !== 1
        && strpos($command, ';') === false
        && strpos($command, '&&') === false
        && strpos($command, '||') === false;
}

/** Download a verified archive, unpack it in the compile workspace, then run caller steps. */
function pmssRunPinnedRemoteArchiveStep(string $label, string $url, string $expectedSha256, string $archiveName, string $sourceDir, string $description, array $postExtractCommands, string $workDir = '/root/compile'): bool
{
    $trimmedWorkDir = rtrim($workDir, '/');
    if (!pmssPinnedRemoteArchiveComponentIsSafe($archiveName)
        || (substr($archiveName, -7) !== '.tar.gz' && substr($archiveName, -7) !== '.tar.xz')
        || !pmssPinnedRemoteArchiveComponentIsSafe($sourceDir)) {
        logmsg("[WARN] Refusing unsafe archive extraction path for {$label}");
        return false;
    }
    if ($workDir === ''
        || $trimmedWorkDir === ''
        || preg_match('/[\x00-\x1F\x7F]/', $workDir) === 1
        || !pmssPathTargetIsSafe($trimmedWorkDir, true, false, false)
    ) {
        logmsg("[WARN] Refusing unsafe archive extraction path for {$label}");
        return false;
    }
    foreach ($postExtractCommands as $command) {
        if (!is_string($command) || !pmssPinnedRemoteArchivePostCommandIsSafe($command)) {
            logmsg("[WARN] Refusing unsafe archive post-extract command for {$label}");
            return false;
        }
    }

    return pmssPinnedRemoteArtifactTempFileUse($label, $url, $expectedSha256, static function (string $archivePath) use ($archiveName, $sourceDir, $description, $postExtractCommands, $workDir): bool {
        $tarMode = substr($archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf';
        $commands = ['set -e', 'mkdir -p '.escapeshellarg($workDir), 'cd '.escapeshellarg($workDir),
            'rm -rf '.escapeshellarg($sourceDir).' '.escapeshellarg($archiveName),
            'cp '.escapeshellarg($archivePath).' '.escapeshellarg($archiveName), 'tar '.$tarMode.' '.escapeshellarg($archiveName)];
        foreach ($postExtractCommands as $command) {
            $commands[] = $command;
        }
        return runStep($description, implode(' && ', $commands)) === 0;
    }) === true;
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
    return pmssPinnedRemoteTempFileUse(
        $label,
        $url,
        $expectedSha256,
        'pmss-remote-deb-',
        "Downloading {$label} package",
        static function (string $tmp) use ($label): bool {
            return pmssEnvFlagEnabled('PMSS_DRY_RUN')
                || runStep("Installing {$label}", dpkgCmd('-i '.escapeshellarg($tmp))) === 0;
        },
        ' package'
    ) === true;
}
