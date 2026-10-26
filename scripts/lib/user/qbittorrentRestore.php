<?php
/** Restore pre-5.3 setting names before a stopped account daemon starts. */
require_once __DIR__.'/qbittorrentConfig.php';
require_once __DIR__.'/qbittorrentLegacyKeys.php';
require_once __DIR__.'/qbittorrentBuild.php';

/** Migration 11 moves WebUI/export folders; migration 12 moves search settings. */
function pmssQbittorrentRestoreContent(string $config, ?string $build = null, ?string $last = null): string
{
    $last = pmssQbittorrentBuildNumber($last);
    $build = pmssQbittorrentBuildNumber($build);
    $fromOld = $last !== null && version_compare($last, '4.4', '<');
    $toOld = $build !== null && version_compare($build, '4.4', '<');
    // Replay renames and the two value enums only; never proxy profiles or DNS policy.
    if ($fromOld && $build !== null && !$toOld) {
        $config = pmssQbittorrentRestoreAliases($config, true, true);
        $config = pmssQbittorrentLegacyEnums($config, true);
        if (version_compare($build, '5.3', '>=')) {
            foreach (pmssQbittorrentConfigValues($config)['Preferences'] ?? [] as $key => $value) {
                if (strpos($key, 'WebUI\\') === 0)
                    $config = pmssQbittorrentConfigSet($config, 'WebUI', substr($key, 6), $value, true);
            }
        }
    }
    $values = pmssQbittorrentConfigValues($config);
    $migrated = pmssQbittorrentWebUiSection($config)[0] === 'WebUI';
    $modern = pmssQbittorrentWebUiSection($config, $last)[0] === 'WebUI';
    foreach ($values['WebUI'] ?? [] as $key => $value) {
        // Once an older build stamps its own version, fill gaps without replacing its edits.
        if ($modern || !array_key_exists('WebUI\\'.$key, $values['Preferences'] ?? [])) {
            $config = pmssQbittorrentConfigSet($config, 'Preferences', 'WebUI\\'.$key, $value, $modern);
        }
    }
    // A repeated pre-4.4 start must retain edits made to the old names since the last run.
    if ($toOld && !$fromOld) {
        $config = pmssQbittorrentRestoreAliases($config, false, true);
        $config = pmssQbittorrentLegacyEnums($config, false);
    }
    if (!$migrated || $fromOld) return $config;
    $mappings = [
        ['BitTorrent', 'Session\\TorrentBackupDirectory', 'Session\\TorrentExportDirectory', 'Session\\TorrentBackupEnabled'],
        ['BitTorrent', 'Session\\FinishedTorrentBackupDirectory', 'Session\\FinishedTorrentExportDirectory', 'Session\\FinishedTorrentBackupDirectoryEnabled'],
        ['Search', 'StoreSearchJobs', 'StoreOpenedSearchTabs', null],
        ['Search', 'StoreSearchJobResults', 'StoreOpenedSearchTabResults', null],
        ['Preferences', 'MailNotification\\SMTPEncryptionType', 'MailNotification\\req_ssl', null],
    ];
    foreach ($mappings as list($section, $new, $old, $enabled)) {
        if (!array_key_exists($new, $values[$section] ?? [])) continue;
        $value = $values[$section][$new];
        if ($enabled !== null && in_array(strtolower(trim($values[$section][$enabled] ?? 'true', " \t\"")), ['false', '0'], true)) $value = '';
        // Qt's SMTPEncryptionType is serialized as None, STARTTLS or SMTPS.
        if ($old === 'MailNotification\\req_ssl') $value = trim($value, " \t\"") === 'SMTPS' ? 'true' : 'false';
        $config = pmssQbittorrentConfigSet($config, $section, $old, $value, true);
    }
    // Migration 9 removed these aliases; the export inverse supplies two sources.
    $config = pmssQbittorrentRestoreAliases($config, false, $toOld && !$fromOld);
    return $toOld && !$fromOld ? pmssQbittorrentLegacyEnums($config, false) : $config;
}

/** Copy just upstream's rename table, keeping proxy profiles and DNS policy intact. */
function pmssQbittorrentRestoreAliases(string $config, bool $forward, bool $overwrite): string
{
    $values = pmssQbittorrentConfigValues($config);
    foreach (pmssQbittorrentLegacyKeys() as $new => $old) {
        if ($forward) list($new, $old) = [$old, $new];
        list($newSection, $newKey) = explode('/', $new, 2);
        list($oldSection, $oldKey) = explode('/', $old, 2);
        $newKey = str_replace('/', '\\', $newKey);
        $oldKey = str_replace('/', '\\', $oldKey);
        if (array_key_exists($newKey, $values[$newSection] ?? [])
            && ($overwrite || !array_key_exists($oldKey, $values[$oldSection] ?? []))) {
            $config = pmssQbittorrentConfigSet($config, $oldSection, $oldKey, $values[$newSection][$newKey], $overwrite);
        }
    }
    return $config;
}
