<?php
/**
 * qBittorrent category preservation helpers for user transfers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/qbittorrentLegacyCategories.php';
require_once __DIR__.'/remoteScripts.php';
/** Build a remote probe that stores qBittorrent category-bearing files in scratch. */
function pmssUserTransferBuildQbittorrentCategoryProbe(array $cfg, string $localConfigPath, string $localCategoriesPath): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'];
    $remoteConfig = $remoteHome.'/.config/qBittorrent/qBittorrent.conf';
    $remoteCategories = $remoteHome.'/.config/qBittorrent/categories.json';
    $readRemote = static function (string $path): string {
        return 'if [ -r '.escapeshellarg($path).' ]; then cat '.escapeshellarg($path).'; fi';
    };

    return "#!/bin/bash\nset -e\numask 077\n"
        .pmssUserTransferBuildSshCommand($cfg['remoteUser'], ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1'])
        .' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg($readRemote($remoteConfig))
        .' > '.escapeshellarg($localConfigPath)."\n"
        .pmssUserTransferBuildSshCommand($cfg['remoteUser'], ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1'])
        .' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg($readRemote($remoteCategories))
        .' > '.escapeshellarg($localCategoriesPath)."\n";
}

/** Preserve source qBittorrent categories without copying server-specific config. */
function pmssUserTransferPreserveQbittorrentCategories(
    array $cfg,
    string $home,
    string $expectPath,
    string $probeScriptPath,
    string $remoteConfigPath,
    string $remoteCategoriesPath
): void {
    $targetPath = rtrim($home, '/').'/.config/qBittorrent/categories.json';
    $existing = pmssUserTransferQbittorrentCategoriesJsonRead($targetPath);
    if ($existing === null || count($existing) > 0) {
        if ($existing === null) logMessage('[WARN] Skipping qBittorrent category preservation (local categories.json is invalid)');
        return;
    }

    $rc = runStep('Reading remote qBittorrent category metadata', pmssBuildCommand($expectPath, [$probeScriptPath]));
    if ($rc !== 0) {
        logMessage(sprintf('[WARN] Skipping qBittorrent category preservation (remote probe rc=%d)', $rc));
        return;
    }

    $source = pmssUserTransferQbittorrentCategoriesJsonRead($remoteCategoriesPath);
    if ($source === null) {
        logMessage('[WARN] Ignoring invalid remote qBittorrent categories.json');
        $source = [];
    }
    if (count($source) < 1) {
        $source = pmssUserTransferQbittorrentLegacyCategoriesFromConfigFile($remoteConfigPath, $cfg);
    }
    if (count($source) < 1) {
        logMessage('[INFO] No remote qBittorrent categories found to preserve');
        return;
    }

    $added = pmssUserTransferQbittorrentCategoriesMerge($targetPath, $existing, $source);
    if ($added < 1) {
        logMessage('[INFO] No new remote qBittorrent categories needed preservation');
        return;
    }
    logMessage(sprintf('[OK] Preserved %d qBittorrent categor%s', $added, $added === 1 ? 'y' : 'ies'));
}

/** Read qBittorrent categories.json as a normalized associative array. */
function pmssUserTransferQbittorrentCategoriesJsonRead(string $path): ?array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = trim((string) @file_get_contents($path));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $categories = [];
    foreach ($decoded as $name => $options) {
        if (!is_string($name) || $name === '' || strpos($name, "\0") !== false) {
            continue;
        }
        $categories[$name] = pmssUserTransferQbittorrentCategoryOptionsNormalize($options);
    }
    return $categories;
}

/** Merge category options into a local categories.json file. */
function pmssUserTransferQbittorrentCategoriesMerge(string $targetPath, array $existing, array $source): int
{
    $added = 0;
    foreach ($source as $name => $options) {
        if (!is_string($name) || $name === '' || isset($existing[$name])) {
            continue;
        }
        $existing[$name] = pmssUserTransferQbittorrentCategoryOptionsNormalize($options);
        $added++;
    }
    if ($added < 1) {
        return 0;
    }

    ksort($existing, SORT_STRING);
    if (!pmssDirEnsureExists(dirname($targetPath), 0755)) {
        logMessage('[WARN] Failed to create qBittorrent config directory for category preservation');
        return 0;
    }
    $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || !pmssAtomicWriteFile($targetPath, $json."\n", 0600)) {
        logMessage('[WARN] Failed to write qBittorrent categories.json');
        return 0;
    }
    return $added;
}

/** Normalize category option payloads to the qBittorrent categories.json schema. */
function pmssUserTransferQbittorrentCategoryOptionsNormalize($options): array
{
    $normalized = is_array($options) ? $options : [];
    if (isset($normalized['savePath']) && !isset($normalized['save_path'])) {
        $normalized['save_path'] = $normalized['savePath'];
        unset($normalized['savePath']);
    }
    if (!isset($normalized['save_path']) || !is_string($normalized['save_path'])) {
        $normalized['save_path'] = '';
    }
    return $normalized;
}
