<?php
/** Install the verified baseline build of the account UI binary. */
require_once __DIR__.'/remoteBinary.php';
require_once __DIR__.'/../rootOwnedTree.php';

// Pin both values from one published release; an empty pin disables installation.
const PMSS_PIKKUMIES_AGENTBOARD_VERSION = 'v0.36.0-pikkumies.5';
const PMSS_PIKKUMIES_AGENTBOARD_SHA256 = 'a737049d1b3b5cd31c8652f7c710cf6ff4e8c0791e903d8b318e7183a472f30c';
const PMSS_PIKKUMIES_AGENTBOARD_ROOT = '/opt/pikkumies-agentboard';

/** An existing version is inspected only when its directory has the expected owner. */
function pmssPikkumiesAgentboardVersionOwned(string $versionDir, int $ownerUid): bool
{
    return is_dir($versionDir) && !is_link($versionDir) && @fileowner($versionDir) === $ownerUid;
}

/** Accept only the two release members and paths beneath the frontend bundle. */
function pmssPikkumiesAgentboardArchiveMembersSafe(string $archive): bool
{
    $members = pmssPinnedRemoteArchiveMembersRead('tar -tzf '.escapeshellarg($archive).' --quoting-style=literal 2>/dev/null', 'tar -tvzf '.escapeshellarg($archive).' --quoting-style=literal 2>/dev/null');
    if ($members === []) return false;
    foreach ($members as $member) {
        // Match the listing row to its name and accept only files or directories.
        if (!in_array($member['type'], ['-', 'd'], true)) return false;
        $path = rtrim($member['path'], '/');
        if ($path !== 'agentboard' && $path !== 'dist' && $path !== 'dist/client'
            && strpos($path, 'dist/client/') !== 0) return false;
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1
            || !pmssPathRelativeStringIsSafe($path, ['allowEmptySegments' => true])) return false;
    }
    return true;
}

/** Return a sorted content manifest after checking every entry without following links. */
function pmssPikkumiesAgentboardTreeManifest(string $dir, int $ownerUid, bool $normalize = false)
{
    clearstatcache(true, $dir);
    if (!pmssPikkumiesAgentboardVersionOwned($dir, $ownerUid)) return false;
    if (!$normalize && (fileperms($dir) & 0777) !== 0755) return false;
    $manifest = [];
    try {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen($dir) + 1);
            $stat = @lstat($path);
            $type = is_array($stat) ? ($stat['mode'] & 0170000) : 0;
            if (!$stat || $stat['uid'] !== $ownerUid || preg_match('/[\x00-\x1f\x7f]/', $relative) === 1
                || !pmssPathWithinResolvedRoot($path, $dir)
                || ($type !== 0040000 && ($type !== 0100000 || $stat['nlink'] !== 1))) return false;
            $mode = $type === 0040000 ? 0755 : ($relative === 'agentboard' ? 0755 : 0644);
            if ($normalize ? !@chmod($path, $mode) : ($stat['mode'] & 0777) !== $mode) return false;
            $digest = $type === 0040000 ? 'dir' : @hash_file('sha256', $path);
            if ($digest === false) return false;
            $manifest[$relative] = $digest;
        }
    } catch (UnexpectedValueException $exception) {
        return false;
    }
    if (!isset($manifest['agentboard'], $manifest['dist'], $manifest['dist/client'], $manifest['dist/client/index.html'])
        || $manifest['dist'] !== 'dir' || $manifest['dist/client'] !== 'dir'
        || $manifest['agentboard'] === 'dir' || $manifest['dist/client/index.html'] === 'dir') return false;
    ksort($manifest, SORT_STRING);
    return $manifest;
}

/** Install one verified archive and switch the stable link by atomic rename. */
function pmssPikkumiesAgentboardArchiveInstall(string $archive, string $version, string $sha256, string $root = PMSS_PIKKUMIES_AGENTBOARD_ROOT, int $ownerUid = 0): bool
{
    // Refuse an unpinned archive before tar or any destination write.
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $version) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/i', $sha256) !== 1
        || !hash_equals(strtolower($sha256), pmssPinnedRemoteChecksum($archive))
        || !pmssPathTargetIsSafe($root, true, false, false)
        || (file_exists($root) && (!is_dir($root) || is_link($root)))) return false;
    if (!is_dir($root) && !@mkdir($root, 0755, true)) return false;
    if (@fileowner($root) !== $ownerUid || !@chmod($root, 0755)) return false;

    $versionDir = $root.'/'.$version;
    if (is_link($versionDir)) return false;
    // Inspect the archive before making an extraction directory.
    if (!pmssPikkumiesAgentboardArchiveMembersSafe($archive)) return false;
    $stage = $root.'/.stage-'.bin2hex(random_bytes(8));
    if (!@mkdir($stage, 0700)) return false;
    try {
        // Inspect every extracted entry before publishing the version.
        $command = 'tar -xzf '.escapeshellarg($archive)
            .' --no-same-owner --no-same-permissions -C '.escapeshellarg($stage).' -- agentboard dist/client';
        $oldUmask = umask(0022);
        try {
            $extractRc = runStep('Extracting Pikkumies Agentboard', $command);
        } finally {
            umask($oldUmask);
        }
        if ($extractRc !== 0) return false;
        $staged = pmssPikkumiesAgentboardTreeManifest($stage, $ownerUid, true);
        if ($staged === false || !@chmod($stage, 0755)) return false;
        if (file_exists($versionDir)) {
            $valid = pmssPikkumiesAgentboardTreeManifest($versionDir, $ownerUid) === $staged;
            if (!$valid) {
                $quarantine = $root.'/.quarantine';
                if ((!is_dir($quarantine) && !@mkdir($quarantine, 0700))
                    || is_link($quarantine) || @fileowner($quarantine) !== $ownerUid
                    || !@chmod($quarantine, 0700)) return false;
                $retired = $quarantine.'/'.$version.'-'.bin2hex(random_bytes(8));
                if (!@rename($versionDir, $retired) || !@rename($stage, $versionDir)) return false;
            }
        } elseif (!@rename($stage, $versionDir)) return false;

        $link = $root.'/agentboard';
        $next = $root.'/.agentboard-next-'.bin2hex(random_bytes(8));
        if (file_exists($link) && !is_link($link)) return false;
        if (!@symlink($version.'/agentboard', $next)) return false;
        $switched = @rename($next, $link);
        if (!$switched) @unlink($next);
        // Record what was published so the next update run can skip the download (a missing stamp only costs one).
        if ($switched) @file_put_contents($root.'/.installed-'.$version, pmssPikkumiesAgentboardStamp($sha256, $staged)."\n");
        return $switched;
    } finally {
        // Remove the extraction tree without following archive links.
        pmssRootOwnedTreeRemove($stage);
    }
}

/** The installed-release stamp: the archive pin plus the content manifest that was published from it. */
function pmssPikkumiesAgentboardStamp(string $sha256, array $manifest): string
{
    return strtolower($sha256).' '.hash('sha256', json_encode($manifest));
}

/** True when this pinned release is published, linked and unchanged since it was installed. */
function pmssPikkumiesAgentboardInstalled(string $version, string $sha256, string $root = PMSS_PIKKUMIES_AGENTBOARD_ROOT, int $ownerUid = 0): bool
{
    $link = $root.'/agentboard';
    $stamp = $root.'/.installed-'.$version;
    if (!is_link($link) || @readlink($link) !== $version.'/agentboard') return false;
    if (!is_file($stamp) || is_link($stamp) || @fileowner($stamp) !== $ownerUid) return false;
    $manifest = pmssPikkumiesAgentboardTreeManifest($root.'/'.$version, $ownerUid);
    return $manifest !== false
        && trim((string) @file_get_contents($stamp, false, null, 0, 256)) === pmssPikkumiesAgentboardStamp($sha256, $manifest);
}

/** Keep the update hook a logged no-op until the release has immutable pins. */
function pmssPikkumiesAgentboardHostEnabled(string $marker = '/etc/seedbox/config/pikkumiesEternals.enabled'): bool
{
    return is_file($marker) && !is_link($marker);
}

function pmssPikkumiesAgentboardInstall(): void
{
    if (!pmssPikkumiesAgentboardHostEnabled()) {
        logmsg('[SKIP] Pikkumies Agentboard host marker is absent');
        return;
    }
    $version = PMSS_PIKKUMIES_AGENTBOARD_VERSION;
    $sha256 = PMSS_PIKKUMIES_AGENTBOARD_SHA256;
    if ($version === '' || $sha256 === '') {
        logmsg('[SKIP] Pikkumies Agentboard release pins are unset');
        return;
    }
    if (!pmssPinnedRemoteAmd64OrSkip('Pikkumies Agentboard', 'install')) return;
    $root = PMSS_PIKKUMIES_AGENTBOARD_ROOT;
    if (pmssPikkumiesAgentboardInstalled($version, $sha256, $root)) {
        logmsg('[SKIP] Pikkumies Agentboard '.$version.' is already installed');
        return;
    }
    $url = 'https://github.com/MagnaCapax/mcxPikkumiesAgentboard/releases/download/'
        .rawurlencode($version).'/agentboard-linux-x64-baseline.tar.gz';
    pmssPinnedRemoteArtifactTempFileUse('Pikkumies Agentboard '.$version, $url, $sha256,
        static function (string $archive) use ($version, $sha256, $root): void {
            if (!pmssPikkumiesAgentboardArchiveInstall($archive, $version, $sha256, $root)) {
                logmsg('[WARN] Pikkumies Agentboard install refused');
            }
        });
}

if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) pmssPikkumiesAgentboardInstall();
