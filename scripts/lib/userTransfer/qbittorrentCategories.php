<?php
/**
 * qBittorrent category preservation helpers for user transfers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/qbittorrentLegacyCategories.php';
require_once __DIR__.'/qbittorrentCategoryPreservation.php';
require_once __DIR__.'/../user/directories.php';
/** Read qBittorrent categories.json as a normalized associative array. */
function pmssUserTransferQbittorrentCategoriesJsonRead(string $path): ?array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $decoded = pmssJsonDecodeAssoc($raw);
    if ($decoded === null) return null;

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
    $accountPath = pmssManagedAccountHomePath($targetPath);
    $context = $accountPath ? pmssRootStageContext($targetPath) : null;
    if (($accountPath && ($context === null || !pmssEnsureUserHomeDir($context[2], $context[1],
        '.config/qBittorrent', 0755))) || (!$accountPath && !pmssDirEnsureExists(dirname($targetPath), 0755))) {
        logMessage('[WARN] Failed to create qBittorrent config directory for category preservation');
        return 0;
    }
    $json = pmssJsonEncodePrettyLine($existing);
    $written = is_string($json) && ($accountPath
        ? (is_array(@lstat($targetPath))
            ? pmssReplaceUserFilePreservingMetadata($targetPath, $json, 0600)
            : pmssReplaceAccountFile($context[2], $context[1], $targetPath, $json, 0600))
        : pmssAtomicWriteFile($targetPath, $json, 0600));
    if (!$written) {
        logMessage('[WARN] Failed to write qBittorrent categories.json');
        return 0;
    }
    return $added;
}

/** Normalize category option payloads to the qBittorrent categories.json schema. */
function pmssUserTransferQbittorrentCategoryOptionsNormalize($options): array
{
    $options = is_array($options) ? $options : [];
    if (isset($options['savePath']) && !isset($options['save_path'])) {
        $options['save_path'] = $options['savePath'];
        unset($options['savePath']);
    }
    if (!isset($options['save_path']) || !is_string($options['save_path'])) {
        $options['save_path'] = '';
    }
    return $options;
}
