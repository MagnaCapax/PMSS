<?php
/** Install a pinned venv archive as a version directory, never as a partial tree. */

/** Accept only regular files and directories with lexical relative member paths. */
function pmssTorrentClientsStoreArchiveIsSafe(string $archive): bool
{
    $paths = [];
    $listing = [];
    $rc = 1;
    @exec('tar -tzf '.escapeshellarg($archive).' 2>/dev/null', $paths, $rc);
    if ($rc !== 0 || $paths === []) return false;
    @exec('tar -tvzf '.escapeshellarg($archive).' 2>/dev/null', $listing, $rc);
    if ($rc !== 0 || count($listing) !== count($paths)) return false;
    foreach ($paths as $index => $path) {
        if ($path === '' || $path[0] === '/'
            || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $path) !== 1
            || !in_array($listing[$index][0] ?? '', ['-', 'd'], true)) return false;
        foreach (explode('/', rtrim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') return false;
        }
    }
    return true;
}

/** Return true only after a verified archive becomes the complete version. */
function pmssTorrentClientsStoreVenvInstall(array $entry, string $binary): bool
{
    $versionDir = dirname($binary);
    if (file_exists($versionDir) || is_link($versionDir)) return false;
    $stage = $versionDir.'.stage-'.getmypid();
    if (file_exists($stage) || is_link($stage) || !@mkdir($stage, 0755)) return false;
    $installed = false;
    try {
        foreach ($entry['url'] as $url) {
            $installed = pmssPinnedRemoteTempFileUse(
                $entry['client'].' '.$entry['version'], $url, $entry['sha256'],
                'pmss-client-venv-', 'Downloading torrent client venv',
                static function (string $archive) use ($stage, $binary, $versionDir): bool {
                    if (!pmssTorrentClientsStoreArchiveIsSafe($archive)) return false;
                    if (runStep('Extracting torrent client venv', 'tar --no-same-owner --no-same-permissions -xzf '
                        .escapeshellarg($archive).' -C '.escapeshellarg($stage)) !== 0) return false;
                    $stagedBinary = $stage.'/'.basename($binary);
                    if (!is_file($stagedBinary) || is_link($stagedBinary)) return false;
                    if (runStep('Setting torrent client venv modes', 'find '.escapeshellarg($stage)
                        .' -type d -exec chmod 0755 {} + && find '.escapeshellarg($stage)
                        .' -type f -exec chmod 0755 {} +') !== 0) return false;
                    return @rename($stage, $versionDir);
                }, '', false
            ) === true;
            if ($installed) break;
        }
    } finally {
        if (!$installed) runStep('Removing incomplete torrent client venv', 'rm -rf -- '.escapeshellarg($stage));
    }
    return $installed;
}
