<?php
/** Creation-time app switches, using existing account writers and launchers. @license GPL-3.0-only */

require_once __DIR__.'/appProfilePolicy.php';
require_once __DIR__.'/../toggleAppCatalog.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/add/pikkumies.php';
require_once __DIR__.'/add/openclaw.php';

/** App IDs and marker paths are trusted definitions, never CLI-supplied paths. */
function pmssUserAppSwitchRegistry(): array
{
    $registry = [
        'rtorrent' => ['apply' => static function (bool $enabled, array $user, string $home): array {
            if ($enabled) return ['message' => 'start scheduled', 'start' => ['Start rTorrent', '/scripts/startRtorrent '.escapeshellarg($user['name'])]];
            $ok = pmssReplaceAccountFile($user['name'], $home, $home.'/.rtorrentDisable', "disabled\n", 0600);
            // A failed disable must retain the previous fatal boundary; otherwise cron could start it.
            return ['message' => $ok ? 'disabled' : 'disable marker failed', 'fatal' => !$ok];
        }],
        // Installs run late, after quotas and the account environment exist; their gates measure both.
        'pikkumies' => ['phase' => 'late', 'apply' => 'pmssAddUserPikkumiesSwitch'],
        'docker' => ['configKey' => 'dockerEnabled', 'configDefault' => true, 'apply' => static function (bool $enabled, array $user, string $home): array {
            $enabled = pmssUserConfigNormaliseToggleValue($user, 'dockerEnabled', $enabled);
            $ram = (int) ($user['memory'] ?? 0);
            if ($ram > 0 && $ram < pmssUserDockerMinRamMiB()) return ['message' => 'off: RAM below '.pmssUserDockerMinRamMiB().' MiB'];
            return ['message' => ($enabled ? 'on' : 'off').': applied through user configuration'];
        }],
        'openclaw' => ['phase' => 'late', 'apply' => 'pmssAddUserOpenClawSwitch'],
    ];
    $markers = ['qbittorrent' => '.qbittorrentEnable', 'deluge' => '.delugeEnable', 'rclone' => '.rcloneEnable'];
    try {
        foreach (pmssToggleAppCatalogRead() as $app => $row) {
            if (isset($row['enable'])) $markers[$app] = $row['enable'];
        }
    } catch (RuntimeException $exception) {
        // Catalog damage must not prevent an otherwise valid account from being created.
        if (function_exists('logProvisionMessage')) logProvisionMessage('App catalog skipped: '.$exception->getMessage());
    }
    foreach ($markers as $app => $marker) {
        $registry[$app] = ['apply' => static function (bool $enabled, array $user, string $home) use ($marker): array {
            if (!$enabled) return ['message' => 'off; no creation marker'];
            $ok = pmssReplaceAccountFile($user['name'], $home, $home.'/'.$marker, '', 0600);
            return ['message' => $ok ? 'enable marker written' : 'enable marker failed'];
        }];
    }
    return $registry;
}

/** Supply config-store defaults before userConfig runs; explicit command-line values win. */
function pmssUserAppProfileConfig(array $user): array
{
    $registry = pmssUserAppSwitchRegistry();
    foreach (pmssUserAppProfileApps($user) as $app => $enabled) {
        $key = $registry[$app]['configKey'] ?? null;
        // A value equal to the policy default is not persisted, so a stored key keeps meaning an explicit choice.
        if ($key === null || isset($user[$key]) || $enabled === ($registry[$app]['configDefault'] ?? null)) continue;
        $user[$key] = $enabled ? 'true' : 'false';
    }
    return $user;
}

/**
 * Apply one phase of a profile: 'early' right after user configuration (markers and service starts), 'late' after
 * post-provision (background installs whose gates need the quota and the account environment). Returns starts.
 */
function pmssUserAppProfileApply(array $user, string $home, ?array $registry = null, string $phase = 'early'): array
{
    $registry = $registry ?? pmssUserAppSwitchRegistry();
    $starts = [];
    foreach (pmssUserAppProfileApps($user) as $app => $enabled) {
        if (!isset($registry[$app])) {
            if ($phase === 'early') logProvisionMessage('App profile '.$app.': unknown app; skipped');
            continue;
        }
        if (($registry[$app]['phase'] ?? 'early') !== $phase) continue;
        $result = $registry[$app]['apply']($enabled, $user, $home);
        logProvisionMessage('App profile '.$app.': '.$result['message']);
        if (!empty($result['fatal'])) pmssAddUserFatalExit('FAIL', 'App profile '.$app.': '.$result['message'], 'app_profile_failed');
        if (isset($result['queue'])) runProvisionStep($result['queue'][0], $result['queue'][1]);
        if (isset($result['start'])) $starts[] = $result['start'];
    }
    return $starts;
}
