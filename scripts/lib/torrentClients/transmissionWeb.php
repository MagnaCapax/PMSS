<?php
/** Converge the optional pinned web client without changing its daemon binary. */

require_once __DIR__.'/catalog.php';
require_once __DIR__.'/transmissionWebArchive.php';
require_once dirname(__DIR__).'/update/apps/remoteBinary.php';

/** Isolate web failures (including injected I/O exceptions) to one warning. */
function pmssTorrentClientsTransmissionWebConverge(array $entry, string $root, array $ops = []): bool
{
    $log = $ops['log'] ?? 'logmsg';
    try {
        if (pmssTorrentClientsTransmissionWebInstall($entry, $root, $ops)) return true;
    } catch (Throwable $error) {
        // Do not leak external command output or allow web failure to abort binary convergence.
    }
    $log('[WARN] Transmission web client install failed; daemon binary left unchanged');
    return false;
}

/** Verify, stage and publish a web tree; all transport/tar operations are injectable. */
function pmssTorrentClientsTransmissionWebInstall(array $entry, string $root, array $ops): bool
{
    $version = $entry['version'] ?? null;
    if (($entry['client'] ?? '') !== 'transmission'
        || ($entry['kind'] ?? '') !== 'static' || !pmssTorrentClientsVersionValid($version)
        || !pmssTorrentClientsCatalogWebValid($entry['web'] ?? null)
        || !pmssPathTargetIsSafe($root, true, true, false)
        || !pmssTorrentClientsStorePathChainSafe($root, 'transmission', $version)) return false;
    $dir = $root.'/transmission/'.$version;
    $target = $dir.'/public_html';
    $marker = $target.'.sha256';
    $binary = $dir.'/transmission-daemon';
    $sha = $entry['web']['sha256'];
    // A direct caller must also prove the daemon pin before installing adjacent assets.
    if (!is_file($binary) || is_link($binary)
        || pmssPinnedRemoteChecksum($binary) !== strtolower($entry['sha256'] ?? '')
        || !pmssPathTargetIsSafe($target, true, true)
        || !pmssPathTargetIsSafe($marker, false, true)) return false;
    [$uid, $gid] = pmssTorrentClientsStoreOwnerIds();
    foreach ([$target, $marker] as $path) {
        $stat = @lstat($path);
        if (is_array($stat) && ($stat['uid'] !== $uid || $stat['gid'] !== $gid
            || ($stat['mode'] & 0022) !== 0 || ($path === $marker && $stat['nlink'] !== 1))) return false;
    }
    if (is_dir($target) && is_file($target.'/index.html') && !is_link($target.'/index.html')
        && pmssReadRegularFileContents($marker) === $sha."\n") return true;
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) return true;

    $stage = $dir.'/.transmission-web-'.bin2hex(random_bytes(8));
    if (!@mkdir($stage, 0700)) return false;
    $preserve = false;
    $download = $ops['download'] ?? static function (string $url, string $path): bool {
        return runStep('Downloading Transmission web client', pmssBuildCommand('wget', ['-q', '-O', $path, $url])) === 0;
    };
    $list = $ops['list'] ?? 'pmssTorrentClientsTransmissionWebArchiveList';
    $extract = $ops['extract'] ?? static function (string $archive, string $stage): bool {
        return runStep('Extracting Transmission web client', 'tar --no-same-owner --no-same-permissions -xzf '
            .escapeshellarg($archive).' -C '.escapeshellarg($stage)) === 0;
    };
    try {
        if (!@chmod($stage, 0700)) return false;
        $archive = $stage.'/web.tar.gz';
        $verified = false;
        foreach ($entry['web']['url'] as $url) {
            if ($download($url, $archive) && is_file($archive) && !is_link($archive)
                && pmssPinnedRemoteChecksum($archive) === $sha) { $verified = true; break; }
        }
        if (!$verified || !pmssTorrentClientsTransmissionWebArchiveSafe($list($archive))
            || !$extract($archive, $stage)
            || !is_dir($stage.'/public_html') || is_link($stage.'/public_html')
            || !pmssTorrentClientsTransmissionWebTreeNormalize($stage.'/public_html')
            || !is_file($stage.'/public_html/index.html')) return false;
        // Prepare the complete marker before moving the prior web tree aside.
        $record = $stage.'/public_html.sha256';
        if (@file_put_contents($record, $sha."\n") !== 65
            || !pmssTorrentClientsTransmissionWebTreeNormalize($record)
            || !pmssTorrentClientsStorePathChainSafe($root, 'transmission', $version)) return false;
        $previous = $stage.'/previous';
        $hadPrevious = is_dir($target);
        if ($hadPrevious && !@rename($target, $previous)) return false;
        if (!@rename($stage.'/public_html', $target)) {
            // Retain the saved tree for recovery if even the rollback rename fails.
            if ($hadPrevious && !@rename($previous, $target)) $preserve = true;
            return false;
        }
        // The old tree is removed only after the verified replacement is in place.
        return @rename($record, $marker);
    } finally {
        if (!$preserve && !pmssTorrentClientsTransmissionWebTreeRemove($stage)) {
            throw new RuntimeException('Transmission web workspace cleanup failed');
        }
    }
}
