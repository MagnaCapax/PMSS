<?php
/** Verified artifact lifetime; loaded by the remoteBinary.php installer facade. */

function pmssPinnedRemoteChecksum(string $path): string
{
    $checksum = @hash_file('sha256', $path);
    return is_string($checksum) ? strtolower($checksum) : '';
}

/** Download, verify, and consume one private artifact; options retain caller-specific diagnostics. */
function pmssPinnedRemoteArtifactTempFileUse(string $label, string $url, string $expectedSha256, callable $callback, bool $runCallbackInDryRun = false, array $options = [])
{
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

    $tmp = pmssCreatePrivateTempFile($options['tempPrefix'] ?? 'pmss-remote-bin-');
    if ($tmp === null) {
        logmsg("[WARN] Unable to create temp file for {$label} download");
        return null;
    }

    // One owner covers failed downloads, rejected pins, dry runs, and callback exceptions.
    try {
        if (runStep($options['downloadDescription'] ?? 'Downloading '.$label, pmssBuildCommand('wget', ['-q', '-O', $tmp, $url])) !== 0) {
            return null;
        }
        if (!pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
            $actualSha = pmssPinnedRemoteChecksum($tmp);
            if ($actualSha !== $expectedSha256) {
                $artifactLabel = $options['artifactLabel'] ?? '';
                logmsg("[WARN] {$label}{$artifactLabel} checksum mismatch; refusing install (expected {$expectedSha256}, got ".($actualSha ?: 'unknown').')');
                return null;
            }
        }

        return (!$runCallbackInDryRun && pmssEnvFlagEnabled('PMSS_DRY_RUN')) ? null : $callback($tmp);
    } finally {
        @unlink($tmp);
    }
}
