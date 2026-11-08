<?php
/** Verified artifact lifetime; loaded by the remoteBinary.php installer facade. */

require_once __DIR__.'/../../../runtime/filesystem.php';

/** Hash only a complete regular-file read, retaining artifact symlink support. */
function pmssPinnedRemoteChecksum(string $path): string
{
    if ($path === '' || strpos($path, "\0") !== false) return '';
    clearstatcache(true, $path);
    // Follow installed binary symlinks, but never open a known device or FIFO.
    $entry = @stat($path);
    if (!is_array($entry) || (($entry['mode'] ?? 0) & 0170000) !== 0100000) return '';
    $handle = @fopen($path, 'rbn');
    if ($handle === false) return '';
    try {
        // A FIFO substituted after stat must not block; a replacement inode is not evidence.
        if (!pmssLockFileHandleMatchesPath($handle, $path, $entry, $opened)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000) return '';
        $hash = hash_init('sha256');
        if (@hash_update_stream($hash, $handle) !== ($opened['size'] ?? null)) return '';
        return hash_final($hash);
    } finally {
        @fclose($handle);
    }
}

/** Keep failed artifacts visible after later steps and the ordinary status summary. */
function pmssPinnedRemoteArtifactFailureRecord(string $label, string $reason): void
{
    static $failures = [];
    if ($failures === []) {
        // Report at shutdown without interrupting permission repair or service recovery.
        register_shutdown_function(static function () use (&$failures): void {
            logMessage('[ERROR] UPDATE INCOMPLETE: pinned artifact failures: '.implode('; ', $failures));
        });
    }
    $failures[] = $label.' ('.$reason.')';
}

/** Download, verify, and consume one private artifact; options retain caller-specific diagnostics. */
function pmssPinnedRemoteArtifactTempFileUse(string $label, string $url, string $expectedSha256, callable $callback, bool $runCallbackInDryRun = false, array $options = [])
{
    $expectedSha256 = strtolower($expectedSha256);
    if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
        logmsg("[WARN] Refusing invalid SHA-256 pin for {$label}");
        pmssPinnedRemoteArtifactFailureRecord($label, 'invalid SHA-256 pin');
        return null;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (strpos($url, 'https://') !== 0
        || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
        || !is_string($host)
        || $host === ''
    ) {
        logmsg("[WARN] Refusing unsafe remote URL for {$label}");
        pmssPinnedRemoteArtifactFailureRecord($label, 'unsafe remote URL');
        return null;
    }

    $tmp = pmssCreatePrivateTempFile($options['tempPrefix'] ?? 'pmss-remote-bin-');
    if ($tmp === null) {
        logmsg("[WARN] Unable to create temp file for {$label} download");
        pmssPinnedRemoteArtifactFailureRecord($label, 'download temp file unavailable');
        return null;
    }

    // One owner covers failed downloads, rejected pins, dry runs, and callback exceptions.
    try {
        $rc = runStep($options['downloadDescription'] ?? 'Downloading '.$label, pmssBuildCommand('wget', ['-q', '-O', $tmp, $url]));
        if ($rc !== 0) {
            pmssPinnedRemoteArtifactFailureRecord($label, 'download failed, rc='.$rc);
            return null;
        }
        if (!pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            $actualSha = pmssPinnedRemoteChecksum($tmp);
            if ($actualSha !== $expectedSha256) {
                $artifactLabel = $options['artifactLabel'] ?? '';
                logMessage("[WARN] {$label}{$artifactLabel} checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')');
                pmssPinnedRemoteArtifactFailureRecord($label, 'SHA-256 verification failed');
                return null;
            }
        }

        return (!$runCallbackInDryRun && pmssEnvFlagEnabled('PMSS_DRY_RUN')) ? null : $callback($tmp);
    } finally {
        @unlink($tmp);
    }
}
