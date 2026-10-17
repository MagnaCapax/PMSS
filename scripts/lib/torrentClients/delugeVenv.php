<?php
/** Build a catalog Deluge version from a root-owned mirror of pinned wheels. */

require_once dirname(__DIR__).'/update/apps/pythonVenv.php';
require_once dirname(__DIR__).'/update/distro.php';
require_once __DIR__.'/storePaths.php';

/** Keep the older wheel compatibility window explicit and host-specific. */
function pmssTorrentClientsDelugeVenvCompatible(array $entry): bool
{
    if (($entry['compatWindow'] ?? '') !== 'bullseye-cp37-310') return true;
    if ((pmssDetectDistro()['version'] ?? 0) !== 11) return false;
    $python = pmssCommandPath('python3');
    $version = $python === '' ? '' : pmssCommandCapture(escapeshellarg($python).' --version 2>&1', 10)['stdout'];
    return preg_match('/\APython 3\.(?:7|8|9|10)(?:\.|\s|\z)/', $version) === 1;
}

/** Return false before invoking pip if the marker, manifest, or mirror is incomplete. */
function pmssTorrentClientsDelugeVenvInstall(array $entry, string $versionDir, array $ops = []): bool
{
    if (!pmssTorrentClientsMarkerEnabled() || ($entry['client'] ?? '') !== 'deluge'
        || ($entry['kind'] ?? '') !== 'venv' || !pmssTorrentClientsDelugeVenvCompatible($entry)
        || !is_array($entry['wheels'] ?? null) || $entry['wheels'] === []) return false;
    $version = $entry['version'] ?? null;
    $storeRoot = dirname(dirname($versionDir));
    if (!is_string($version) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
        || $versionDir !== $storeRoot.'/deluge/'.$version
        || !pmssTorrentClientsStorePathChainSafe($storeRoot, 'deluge', $version)
        || !pmssTorrentClientsStorePathChainSafe($storeRoot, 'wheels')) return false;

    $wheelDir = $storeRoot.'/wheels';
    $paths = [];
    $seen = [];
    foreach ($entry['wheels'] as $wheel) {
        if (!is_array($wheel) || !is_string($wheel['file'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.whl\z/', $wheel['file']) !== 1
            || !is_string($wheel['sha256'] ?? null)
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $wheel['sha256']) !== 1) return false;
        $path = $wheelDir.'/'.$wheel['file'];
        $stat = @lstat($path);
        $uid = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE ? posix_geteuid() : 0;
        if (isset($seen[$path]) || !is_array($stat) || $stat['uid'] !== $uid
            || !is_file($path) || is_link($path)
            || hash_file('sha256', $path) !== strtolower($wheel['sha256'])) return false;
        $seen[$path] = true;
        $paths[] = $path;
    }
    $link = $versionDir.'/.deluge-cli';
    if (file_exists($link) || is_link($link)) return false;
    $install = $ops['install'] ?? 'pmssPythonVenvInstallCli';
    $log = $ops['log'] ?? 'logmsg';
    $install($versionDir, 'Deluge '.$version,
        [['Installing pinned Deluge wheels', '--no-index --no-deps '.implode(' ', array_map('escapeshellarg', $paths))]],
        $versionDir.'/bin/deluged', $link,
        '[WARN] Skipping Deluge venv: python3 missing', '[WARN] Deluge venv missing deluged', $log);
    $linked = is_link($link) && readlink($link) === $versionDir.'/bin/deluged';
    if (is_link($link)) @unlink($link);
    // InstallCli calls pmssPythonVenvEnsure; both daemon scripts must exist.
    return $linked && is_file($versionDir.'/bin/deluged') && !is_link($versionDir.'/bin/deluged')
        && is_file($versionDir.'/bin/deluge-web') && !is_link($versionDir.'/bin/deluge-web');
}
