<?php
/** Repair absent WebUI settings upstream of the strict port reconciler. */
require_once __DIR__.'/torrentPort.php';

/** Only a readable configuration with keys and a valid assignment can request repair. */
function pmssQbittorrentPortRepairNeeded(string $user, string $home, ?string $build = null): bool
{
    $path = $home.'/.config/qBittorrent/qBittorrent.conf';
    if (!pmssTorrentPortUserHomeContextIsSafe($user, $home)
        || !pmssUserFilePathIsSafe($path)
        || pmssTorrentPortExpectedRead($home.'/.qbittorrentPort') === null) return false;
    $config = pmssReadRegularFileContents($path);
    if (!is_string($config) || pmssQbittorrentConfigValues($config) === []) return false;
    $build = $build ?? pmssQbittorrentBuildVersion($home);
    return trim((string) pmssQbittorrentWebUiValue($config, 'Port', $build), " \t\"") === '';
}

/** Called as the account, under its switch lock and after pending-save restoration. */
function pmssQbittorrentPortRepairMissing(string $user, string $home, array $ops = []): bool
{
    $build = $ops['build'] ?? null;
    if (!pmssQbittorrentPortRepairNeeded($user, $home, $build)) return true;
    $build = $build ?? pmssQbittorrentBuildVersion($home);
    $pids = $ops['accountPids'] ?? 'pmssQbittorrentAccountPids';
    if ($pids() !== []) return false;
    $expected = pmssTorrentPortExpectedRead($home.'/.qbittorrentPort');
    if ($expected === null) return false;
    // Recheck the current bytes; never overwrite a port that appeared after inspection.
    return pmssQbittorrentConfigMutate($user, static function (string $config) use ($build, $expected): ?string {
        if (trim((string) pmssQbittorrentWebUiValue($config, 'Port', $build), " \t\"") !== '') return null;
        $updated = pmssQbittorrentConfigUpsert($config, 'Preferences', 'WebUI\\Port', (string) $expected);
        return pmssQbittorrentConfigUpsert($updated, 'Preferences', 'WebUI\\Address', '127.0.0.1');
    }, $home.'/.config/qBittorrent/qBittorrent.conf');
}
