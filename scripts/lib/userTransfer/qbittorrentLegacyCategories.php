<?php
/**
 * Legacy qBittorrent category readers for user transfers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/qtVariant.php';

/** Extract legacy Session\Categories entries from qBittorrent.conf content. */
function pmssUserTransferQbittorrentLegacyCategoriesFromConfig(string $contents, array $cfg): array
{
    $encoded = pmssUserTransferQbittorrentConfigValue($contents, 'Session\\Categories');
    if ($encoded === null || stripos($encoded, '@variant(') !== 0 || substr($encoded, -1) !== ')') {
        return [];
    }

    $raw = pmssUserTransferQtVariantStringMap(pmssUserTransferQtSettingsBytesDecode(substr($encoded, 9, -1)));
    $categories = [];
    foreach ($raw as $name => $savePath) {
        if ($name === '' || strpos($name, "\0") !== false) {
            continue;
        }
        $categories[$name] = [
            'save_path' => pmssUserTransferQbittorrentCategoryPathRewrite((string) $savePath, $cfg),
        ];
    }
    return $categories;
}

/** Find a qBittorrent config key value without trusting unrelated sections. */
function pmssUserTransferQbittorrentConfigValue(string $contents, string $targetKey): ?string
{
    $section = '';
    foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        if ($trimmed[0] === '[' && substr($trimmed, -1) === ']') {
            $section = substr($trimmed, 1, -1);
            continue;
        }

        $eq = strpos($trimmed, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $eq));
        if ($key === $targetKey && ($section === '' || $section === 'BitTorrent')) {
            return trim(substr($trimmed, $eq + 1));
        }
    }
    return null;
}

/** Rewrite category save paths when the source and target usernames differ. */
function pmssUserTransferQbittorrentCategoryPathRewrite(string $path, array $cfg): string
{
    $remote = '/home/'.(string) ($cfg['remoteUser'] ?? '');
    $local = '/home/'.(string) ($cfg['localUser'] ?? '');
    if ($remote === $local || $remote === '/home/' || $path === '') {
        return $path;
    }
    if ($path === $remote) {
        return $local;
    }
    return strpos($path, $remote.'/') === 0 ? $local.substr($path, strlen($remote)) : $path;
}
