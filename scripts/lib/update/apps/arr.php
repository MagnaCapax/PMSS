<?php
/**
 * Shared helpers for *ARR (Sonarr/Radarr) style application updaters.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

// INSTALL !== EXECUTION. This module downloads, extracts and activates ARR releases;
// it MUST NEVER execute an installed binary. Version detection reads metadata only.
// A *arr binary invoked with an unrecognised argument (Radarr 6.2.0 has no --version)
// ignores it and performs a full application Bootstrap, then reparents to PID 1 when
// the updater exits -- a permanent root daemon on a public port with the first-run
// defaults BindAddress=* / AuthenticationMethod=None. Bounding the probe TIMEOUT bounds
// the parent's wait, not the child; that "fix" (GH #527) converted a visible update hang
// into a silent root daemon and produced the 2026-07-03 root compromise of a fleet host.
const PMSS_ARR_APP_BRANCHES = ['Lidarr' => 'develop|master', 'Prowlarr' => 'develop|master', 'Radarr' => 'develop|master', 'Readarr' => 'develop|master', 'Sonarr' => 'main|develop'];
require_once __DIR__.'/../rootOwnedTree.php';

require_once __DIR__.'/arr/config.php';
require_once __DIR__.'/arr/release.php';
require_once __DIR__.'/arr/activation.php';

function pmssArrUpdate(array $config): void
{
    $app = is_string($config['app'] ?? null) && $config['app'] !== ''
        ? $config['app']
        : 'ARR';
    $runtimePath = dirname(__DIR__, 2).'/runtime.php';
    if (!@include_once $runtimePath) {
        if (defined('STDERR')) {
            fwrite(STDERR, sprintf('%s updater: missing runtime helper at %s, skipping install.', $app, $runtimePath).PHP_EOL);
        }
        return;
    }

    $log = static function (string $message) use ($app): void {
        logMessage($app.': '.$message);
    };

    $config = pmssArrNormalizeConfig($config, $log);
    if ($config === null) {
        return;
    }

    $payload = @file_get_contents($config['releases_url'], false, stream_context_create([
        'http' => [
            'header'  => [
                'Accept: application/vnd.github+json',
                'User-Agent: '.$config['user_agent'],
            ],
            'timeout' => 15,
        ],
    ]));
    if ($payload === false) { $log('Unable to fetch release metadata (network issue?)'); return; }

    $releases = pmssJsonDecodeAssoc($payload);
    if ($releases === null) { $log('Invalid release metadata payload'); return; }

    $architecture = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null'));
    $asset = pmssArrReleaseAssetSelect($releases, $config['asset_pattern'], $architecture);
    if ($asset === null) { $log('No suitable linux release asset found'); return; }
    [$latestVersion, $downloadUrl, $assetName] = $asset;

    $installPath = $config['install_path'];

    $needsRefresh = is_dir($installPath) && pmssRootOwnedTreeNeedsRefresh($installPath);
    $currentVersion = $needsRefresh ? null : pmssArrInstalledVersionRead($installPath);
    if ($needsRefresh) {
        $log('install has non-root-owned entries; reinstalling');
    } elseif ($currentVersion === $latestVersion && is_dir($installPath)) {
        $log("Already at {$latestVersion}, skipping update");
        return;
    } elseif ($currentVersion !== null && $currentVersion !== $latestVersion) {
        $log("Updating from {$currentVersion} to {$latestVersion}");
    } elseif ($currentVersion === null && is_dir($installPath)) {
        $log('Unknown installed version; reinstalling to avoid stale binaries');
    }

    if (pmssArrReleaseActivate($config, $app, $downloadUrl, $assetName, $log)) {
        if (@file_put_contents($installPath.'/version.txt', $latestVersion.PHP_EOL) === false) {
            $log('Failed to persist installed version marker');
        }
        $log("Installed version {$latestVersion}");
    }
}

/** Build the existing release config for one shared ARR app. */
function pmssArrAppConfig(string $app, string $branchPattern): array
{
    return [
        'app' => $app,
        'install_path' => '/opt/'.$app,
        'releases_url' => 'https://api.github.com/repos/'.$app.'/'.$app.'/releases',
        'asset_pattern' => sprintf('/%s\\.(?:%s)\\.([0-9.]+).*linux.*tar\\.gz/i', preg_quote($app, '/'), $branchPattern),
        'extract_dir' => $app,
        'user_agent' => 'PMSS-'.$app,
    ];
}

/** Refresh only existing shared ARR trees with entries owned by another UID. */
function pmssArrConvergeSharedInstallOwnership(): void
{
    foreach (PMSS_ARR_APP_BRANCHES as $app => $branchPattern) {
        $installPath = '/opt/'.$app;
        if (!is_dir($installPath) || !pmssRootOwnedTreeNeedsRefresh($installPath)) {
            continue;
        }
        try {
            pmssArrUpdate(pmssArrAppConfig($app, $branchPattern));
        } catch (Throwable $exception) {
            logmsg($app.': shared install refresh failed: '.$exception->getMessage());
        }
    }
}

/** Run every supported Servarr updater from the single app entrypoint. */
function pmssArrUpdateSupportedApps(): void
{
    foreach (PMSS_ARR_APP_BRANCHES as $app => $branchPattern) {
        if ($app === 'Sonarr') { @unlink('/etc/apt/sources.list.d/sonarr.list'); @passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null'); }

        pmssArrUpdate(pmssArrAppConfig($app, $branchPattern));
    }
}
