<?php
/** Remote category probes and preservation ordering; storage stays in qbittorrentCategories.php. */
/** @license GPL-3.0-only */
require_once __DIR__.'/remoteScripts.php';

/** Build a remote probe that stores qBittorrent category-bearing files in scratch. */
function pmssUserTransferBuildQbittorrentCategoryProbe(array $cfg, string $localConfigPath, string $localCategoriesPath): string
{
    $script = "#!/bin/bash\nset -e\numask 077\n";
    foreach (['qBittorrent.conf' => $localConfigPath, 'categories.json' => $localCategoriesPath] as $file => $localPath) {
        $remotePath = escapeshellarg('/home/'.$cfg['remoteUser'].'/.config/qBittorrent/'.$file);
        $script .= pmssUserTransferBuildSshProbeCommand($cfg, 'if [ -r '.$remotePath.' ]; then cat '.$remotePath.'; fi')
            .' > '.escapeshellarg($localPath)."\n";
    }
    return $script;
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
    if (count($source) < 1 && is_file($remoteConfigPath)) {
        $source = pmssUserTransferQbittorrentLegacyCategoriesFromConfig(
            (string) @file_get_contents($remoteConfigPath),
            $cfg
        );
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
