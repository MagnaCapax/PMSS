<?php
/** Install pinned static, archived and wheel-based store artifacts. */

require_once dirname(__DIR__).'/update/apps/remoteBinary.php';
require_once __DIR__.'/delugeVenv.php';

/** Dispatch installation without owning store traversal or publication policy. */
function pmssTorrentClientsStoreInstall(array $entry, string $binary): bool
{
    if ($entry['kind'] === 'venv') return $entry['client'] === 'deluge'
        ? pmssTorrentClientsDelugeVenvInstall($entry, dirname(dirname($binary)))
        : pmssTorrentClientsStoreVenvInstall($entry, $binary);
    foreach ($entry['url'] as $url) {
        pmssInstallPinnedRemoteBinary(
            $entry['client'].' '.$entry['version'], $url, $entry['sha256'], $binary, true
        );
        if (is_file($binary) && hash_file('sha256', $binary) === strtolower($entry['sha256'])) return true;
    }
    return false;
}

/** Accept only regular files and directories with lexical relative member paths. */
function pmssTorrentClientsStoreArchiveIsSafe(string $archive): bool
{
    // Invalid filenames must fail before shell quoting can throw or truncate them.
    if ($archive === '' || pmssFilesystemPathHasNulByte($archive)) return false;
    $members = @pmssPinnedRemoteArchiveMembersRead('tar -tzf '.escapeshellarg($archive).' 2>/dev/null', 'tar -tvzf '.escapeshellarg($archive).' 2>/dev/null');
    if ($members === []) return false;
    foreach ($members as $member) {
        $path = $member['path'];
        if ($path === '' || $path[0] === '/'
            || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $path) !== 1
            || !in_array($member['type'], ['-', 'd'], true)) return false;
        if (!pmssPathRelativeStringIsSafe(rtrim($path, '/'))) return false;
    }
    return true;
}

/** Return true only after a verified archive becomes the complete version. */
function pmssTorrentClientsStoreVenvInstall(array $entry, string $binary): bool
{
    $versionDir = dirname($binary);
    if (pmssPathExistsOrLink($versionDir)) return false;
    $stage = $versionDir.'.stage-'.getmypid();
    if (pmssPathExistsOrLink($stage) || !@mkdir($stage, 0755)) return false;
    $installed = false;
    try {
        foreach ($entry['url'] as $url) {
            $installed = pmssPinnedRemoteArtifactTempFileUse(
                $entry['client'].' '.$entry['version'], $url, $entry['sha256'],
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
                }, false, ['tempPrefix' => 'pmss-client-venv-', 'downloadDescription' => 'Downloading torrent client venv']
            ) === true;
            if ($installed) break;
        }
    } finally {
        if (!$installed) runStep('Removing incomplete torrent client venv', 'rm -rf -- '.escapeshellarg($stage));
    }
    return $installed;
}
