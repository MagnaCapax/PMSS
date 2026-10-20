<?php
/** Install the verified baseline build of the account UI binary. */
require_once __DIR__.'/remoteBinary.php';

// Fill both pins from one published release. Empty pins deliberately disable installation.
const PMSS_PIKKUMIES_AGENTBOARD_VERSION = '';
const PMSS_PIKKUMIES_AGENTBOARD_SHA256 = '';
const PMSS_PIKKUMIES_AGENTBOARD_ROOT = '/opt/pikkumies-agentboard';

/** Refuse an unpinned archive before tar or any destination write. */
function pmssPikkumiesAgentboardArchiveVerified(string $archive, string $sha256): bool
{
    return preg_match('/\A[a-f0-9]{64}\z/i', $sha256) === 1
        && hash_equals(strtolower($sha256), pmssPinnedRemoteChecksum($archive));
}

/** Install one verified archive and switch the stable link by atomic rename. */
function pmssPikkumiesAgentboardArchiveInstall(string $archive, string $version, string $sha256, string $root = PMSS_PIKKUMIES_AGENTBOARD_ROOT, int $ownerUid = 0): bool
{
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $version) !== 1
        || !pmssPikkumiesAgentboardArchiveVerified($archive, $sha256)
        || !pmssPathTargetIsSafe($root, true, false, false)
        || (file_exists($root) && (!is_dir($root) || is_link($root)))) return false;
    if (!is_dir($root) && !@mkdir($root, 0755, true)) return false;
    if (@fileowner($root) !== $ownerUid) return false;

    $versionDir = $root.'/'.$version;
    if (is_link($versionDir)) return false;
    $stage = $root.'/.stage-'.bin2hex(random_bytes(8));
    if (!@mkdir($stage, 0700)) return false;
    try {
        // Only the expected member is extracted; no archive owner or permissions survive.
        $command = 'tar -xzf '.escapeshellarg($archive)
            .' --no-same-owner --no-same-permissions -C '.escapeshellarg($stage).' -- agentboard';
        $oldUmask = umask(0022);
        try {
            $extractRc = runStep('Extracting Pikkumies Agentboard', $command);
        } finally {
            umask($oldUmask);
        }
        if ($extractRc !== 0) return false;
        $entry = @lstat($stage.'/agentboard');
        if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1) return false;
        if (!@chmod($stage.'/agentboard', 0755) || !@chmod($stage, 0755)) return false;
        if (file_exists($versionDir)) {
            $ownedDir = is_dir($versionDir) && @fileowner($versionDir) === $ownerUid;
            $installed = $ownedDir ? @lstat($versionDir.'/agentboard') : false;
            $valid = $ownedDir && is_array($installed)
                && ($installed['mode'] & 0170000) === 0100000 && $installed['uid'] === $ownerUid
                && $installed['nlink'] === 1
                && @hash_file('sha256', $versionDir.'/agentboard') === @hash_file('sha256', $stage.'/agentboard');
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
        return $switched;
    } finally {
        // Stage is root-owned and never contains an account-controlled path.
        if (is_file($stage.'/agentboard') || is_link($stage.'/agentboard')) @unlink($stage.'/agentboard');
        if (is_dir($stage)) @rmdir($stage);
    }
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
    if (is_link($root.'/agentboard') && readlink($root.'/agentboard') === $version.'/agentboard') return;
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
