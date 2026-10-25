<?php
/** Build a catalog Deluge version from a root-owned mirror of pinned wheels. */

require_once dirname(__DIR__).'/update/apps/pythonVenv.php';
require_once dirname(__DIR__).'/update/apps/delugeWebBasePatch.php';
require_once dirname(__DIR__).'/update/distro.php';
require_once __DIR__.'/storePaths.php';
require_once __DIR__.'/store.php';

/** Check the reviewed wheel set's distro, architecture and Python window before pip. */
function pmssTorrentClientsDelugeVenvCompatible(array $entry): bool
{
    $window = $entry['compatWindow'] ?? '';
    if ($window === '') return true;
    if (!in_array($window, ['bullseye-cp37-310', 'bookworm-cp311'], true)) return false;
    $bookworm = $window === 'bookworm-cp311';
    if ((pmssDetectDistro()['version'] ?? 0) !== ($bookworm ? 12 : 11)
        || ($bookworm && php_uname('m') !== 'x86_64')) return false;
    $python = pmssCommandPath('python3');
    $version = $python === '' ? '' : pmssCommandCapture(escapeshellarg($python).' --version 2>&1', 10)['stdout'];
    return preg_match($bookworm ? '/\APython 3\.11(?:\.|\s|\z)/'
        : '/\APython 3\.(?:7|8|9|10)(?:\.|\s|\z)/', $version) === 1;
}

/** Return false before invoking pip if the manifest or mirror is incomplete. */
function pmssTorrentClientsDelugeVenvInstall(array $entry, string $versionDir, array $ops = []): bool
{
    if (($entry['client'] ?? '') !== 'deluge'
        || ($entry['kind'] ?? '') !== 'venv' || !pmssTorrentClientsDelugeVenvCompatible($entry)
        || !is_array($entry['wheels'] ?? null) || $entry['wheels'] === []) return false;
    $version = $entry['version'] ?? null;
    $storeRoot = dirname(dirname($versionDir));
    if (!pmssTorrentClientsVersionValid($version)
        || $versionDir !== $storeRoot.'/deluge/'.$version
        || !pmssTorrentClientsStorePathChainSafe($storeRoot, 'deluge', $version)
        || !pmssTorrentClientsStorePathChainSafe($storeRoot, 'wheels')) return false;

    $wheelDir = $storeRoot.'/wheels';
    // PM's package mirror fills the root-owned wheel set; the hash check below stays authoritative.
    $fetch = $ops['fetch'] ?? static function (string $file, string $sha256, string $path): void {
        pmssInstallPinnedRemoteBinary('Deluge wheel '.$file,
            'https://pulsedmedia.com/remote/pkg/wheels/'.rawurlencode($file), $sha256, $path, true);
    };
    if (!pmssTorrentClientsStoreDirectory($wheelDir)) return false;
    $paths = [];
    $seen = [];
    foreach ($entry['wheels'] as $wheel) {
        if (!is_array($wheel) || !is_string($wheel['file'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.whl\z/', $wheel['file']) !== 1
            || !is_string($wheel['sha256'] ?? null)
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $wheel['sha256']) !== 1) return false;
        $path = $wheelDir.'/'.$wheel['file'];
        if (!isset($seen[$path]) && (!is_file($path) || is_link($path)
            || hash_file('sha256', $path) !== strtolower($wheel['sha256']))) {
            $fetch($wheel['file'], strtolower($wheel['sha256']), $path);
            clearstatcache(true, $path);
        }
        $stat = @lstat($path);
        if (isset($seen[$path]) || !is_array($stat) || $stat['uid'] !== pmssTorrentClientsStoreOwnerIds()[0]
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
        [['Installing pinned Deluge wheels', '--no-index --no-deps '.pmssCommandArgvShellQuote($paths)]],
        $versionDir.'/bin/deluged', $link,
        '[WARN] Skipping Deluge venv: python3 missing', '[WARN] Deluge venv missing deluged', $log);
    $linked = is_link($link) && readlink($link) === $versionDir.'/bin/deluged';
    $unlink = $ops['unlink'] ?? static function (string $path): bool { return @unlink($path); };
    // Do not publish a version with the installer's temporary CLI link left behind.
    if (is_link($link) && !$unlink($link)) return false;
    // InstallCli calls pmssPythonVenvEnsure; both daemon scripts must exist.
    return $linked && is_file($versionDir.'/bin/deluged') && !is_link($versionDir.'/bin/deluged')
        && is_file($versionDir.'/bin/deluge-web') && !is_link($versionDir.'/bin/deluge-web')
        && pmssDelugeWebBasePathPatchVenv($versionDir, $log, $ops['patch'] ?? 'pmssPatchDelugeWebBasePathBytes');
}
