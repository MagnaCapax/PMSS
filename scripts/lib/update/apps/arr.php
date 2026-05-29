<?php
/**
 * Shared helpers for *ARR (Sonarr/Radarr) style application updaters.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

// Bound ARR binary probes so daemonizing applications cannot wedge updates.
const PMSS_ARR_VERSION_PROBE_TIMEOUT_SECONDS = 50;
const PMSS_ARR_APP_BRANCHES = ['Lidarr' => 'develop|master', 'Prowlarr' => 'develop|master', 'Radarr' => 'develop|master', 'Readarr' => 'develop|master', 'Sonarr' => 'main|develop'];

/**
 * Orchestrate the full update flow for a Starr-family application.
 *
 * Expected keys in the configuration array:
 *  - app: Human-readable application label (e.g. "Radarr")
 *  - install_path: Destination directory for the unpacked application
 *  - releases_url: GitHub API URL listing release metadata
 *  - asset_pattern: PCRE that captures the semantic version from asset names
 *  - extract_dir: Directory name created inside the tarball after extraction
 *  - user_agent: HTTP user-agent value for GitHub API requests
 */
function pmssArrAssetNameHasToken(string $assetName, array $tokens): bool
{
    $name = strtolower($assetName);
    foreach ($tokens as $token) {
        if (preg_match('/(?:^|[^a-z0-9])'.preg_quote(strtolower($token), '/').'(?=[^a-z0-9]|$)/', $name)) {
            return true;
        }
    }

    return false;
}

/** Build the canonical updater config for a supported Starr-family app. */
function pmssArrAppConfig(string $app): ?array
{
    if (!isset(PMSS_ARR_APP_BRANCHES[$app])) {
        return null;
    }

    return [
        'app' => $app,
        'install_path' => '/opt/'.$app,
        'releases_url' => 'https://api.github.com/repos/'.$app.'/'.$app.'/releases',
        'asset_pattern' => sprintf('/%s\\.(?:%s)\\.([0-9.]+).*linux.*tar\\.gz/i', preg_quote($app, '/'), PMSS_ARR_APP_BRANCHES[$app]),
        'extract_dir' => $app,
        'user_agent' => 'PMSS-'.$app,
    ];
}

/** Return the supported Servarr app labels in deterministic installer order. */
function pmssArrSupportedApps(): array
{
    return array_keys(PMSS_ARR_APP_BRANCHES);
}

/**
 * Reject config values that could break shell/file boundaries.
 */
function pmssArrIsSafeConfigValue(string $value): bool
{
    return $value !== '' && preg_match('/[\r\n\0]/', $value) !== 1;
}

/**
 * Reject absolute paths that could resolve outside the intended target.
 */
function pmssArrInstallPathIsSafe(string $path): bool
{
    require_once dirname(__DIR__, 2).'/pathSafety.php';
    return pmssPathAbsoluteStringIsSafe($path);
}

/**
 * Normalize required config and fail closed on unsafe runtime inputs.
 */
function pmssArrNormalizeConfig(array $config, callable $log): ?array
{
    foreach (['app', 'install_path', 'releases_url', 'asset_pattern', 'extract_dir'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || !pmssArrIsSafeConfigValue($config[$key])) {
            $log('Invalid updater configuration: '.$key);
            return null;
        }
    }

    if (!pmssArrInstallPathIsSafe($config['install_path'])) {
        $log('Invalid updater configuration: install_path');
        return null;
    }
    if ($config['extract_dir'] === '.'
        || $config['extract_dir'] === '..'
        || basename($config['extract_dir']) !== $config['extract_dir']
    ) {
        $log('Invalid updater configuration: extract_dir');
        return null;
    }
    if (@preg_match($config['asset_pattern'], '') === false) {
        $log('Invalid updater configuration: asset_pattern');
        return null;
    }

    $config['user_agent'] = isset($config['user_agent'])
        && is_string($config['user_agent'])
        && pmssArrIsSafeConfigValue($config['user_agent'])
        ? $config['user_agent']
        : 'PMSS-ARR';

    return $config;
}

/** Extract a semantic version from ARR metadata or CLI output. */
function pmssArrVersionExtract(string $payload): ?string
{
    return preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $payload, $match) === 1
        ? $match[1]
        : null;
}

/** Build the shell-safe command used by the shared bounded app probe. */
function pmssArrVersionProbeCommand(string $binary, string $flag): string
{
    return function_exists('pmssBuildCommand')
        ? pmssBuildCommand($binary, [$flag])
        : escapeshellarg($binary).' '.escapeshellarg($flag);
}

/** Run an ARR version probe through the shared app probe timeout path. */
function pmssArrVersionProbeRun(string $binary, string $flag): string
{
    require_once __DIR__.'/remoteBinary.php';
    return trim(pmssAppVersionProbeOutput(pmssArrVersionProbeCommand($binary, $flag), PMSS_ARR_VERSION_PROBE_TIMEOUT_SECONDS));
}

/** Prefer cheap version files, then bounded binary probes, to detect installed ARR versions. */
function pmssArrInstalledVersionRead(string $installPath, string $app): ?string
{
    foreach ([$installPath.'/version.txt', $installPath.'/VERSION'] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $versionPayload = @file_get_contents($file);
        if (is_string($versionPayload) && $versionPayload !== '') {
            $version = pmssArrVersionExtract($versionPayload);
            if ($version !== null) {
                return $version;
            }
        }
    }

    foreach ([$installPath.'/'.$app, $installPath.'/'.strtolower($app), $installPath.'/'.$app.'.exe'] as $binary) {
        if (!is_executable($binary)) {
            continue;
        }

        foreach (['--version', '-v'] as $flag) {
            $output = pmssArrVersionProbeRun($binary, $flag);
            if ($output === '') {
                continue;
            }

            $version = pmssArrVersionExtract($output);
            if ($version !== null) {
                return $version;
            }
        }
    }

    return null;
}

/** Select the release asset matching the host architecture, or a generic build.
 * @return array{0:string,1:string,2:string}|null
 */
function pmssArrReleaseAssetSelect(array $releases, string $assetPattern, string $architecture): ?array
{
    $targetArchitectureTokens = ['arm64' => ['arm64', 'aarch64'], 'armhf' => ['armhf', 'armv7', 'arm']][$architecture] ?? ['x64', 'amd64'];
    $knownArchitectureTokens = ['x64', 'amd64', 'arm64', 'aarch64', 'armhf', 'armv7', 'arm'];
    $genericAsset = null;

    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) { continue; }
        foreach ($release['assets'] as $candidateAsset) {
            $name = (string) ($candidateAsset['name'] ?? '');
            if (!pmssArrIsSafeConfigValue($name) || basename($name) !== $name
                || strpos($name, '/') !== false || strpos($name, '\\') !== false
                || !preg_match($assetPattern, $name, $match)) {
                continue;
            }
            $url = (string) ($candidateAsset['browser_download_url'] ?? '');
            if ($url === '') { continue; }
            $candidate = [$match[1], $url, $name];
            if (pmssArrAssetNameHasToken($name, $targetArchitectureTokens)) {
                return $candidate;
            }
            if ($genericAsset === null && !pmssArrAssetNameHasToken($name, $knownArchitectureTokens)) {
                $genericAsset = $candidate;
            }
        }
    }

    return $genericAsset;
}

function pmssArrUpdate(array $config): void
{
    $app = isset($config['app']) && is_string($config['app']) && $config['app'] !== ''
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

    $currentVersion = pmssArrInstalledVersionRead($installPath, $app);
    if ($currentVersion === $latestVersion && is_dir($installPath)) {
        $log("Already at {$latestVersion}, skipping update");
        return;
    }
    if ($currentVersion !== null && $currentVersion !== $latestVersion) {
        $log("Updating from {$currentVersion} to {$latestVersion}");
    }
    if ($currentVersion === null && is_dir($installPath)) {
        $log('Unknown installed version; reinstalling to avoid stale binaries');
    }

    $workDir = pmssCreatePrivateTempDir(strtolower($app).'-');
    if ($workDir === null) { $log('Failed to create temporary workspace'); return; }

    $archivePath = $workDir.'/'.$assetName;
    $extractPath = $workDir.'/'.$config['extract_dir'];
    $installed = false;
    if (runCommand(sprintf('curl -sSL --fail -o %s %s', escapeshellarg($archivePath), escapeshellarg($downloadUrl))) !== 0
        || !is_file($archivePath)
    ) {
        $log('Download failed; keeping existing installation');
    } elseif (runCommand(sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir))) !== 0
        || !is_dir($extractPath)
    ) {
        $log('Extraction failed; keeping existing installation');
    } elseif (!is_dir(dirname($installPath))) {
        $log('Install parent directory missing; refusing to replace application');
    } elseif (runCommand('rm -rf '.escapeshellarg($installPath)) !== 0) {
        $log('Failed to remove existing installation; keeping existing installation');
    } elseif (runCommand(sprintf('mv %s %s', escapeshellarg($extractPath), escapeshellarg($installPath))) !== 0
        || !is_dir($installPath)
    ) {
        $log('Failed to activate extracted release');
    } else {
        $installed = true;
    }

    runCommand('rm -rf '.escapeshellarg($workDir));
    if ($installed) {
        if (@file_put_contents($installPath.'/version.txt', $latestVersion.PHP_EOL) === false) {
            $log('Failed to persist installed version marker');
        }
        $log("Installed version {$latestVersion}");
    }
}

/** Run every supported Servarr updater from the single app entrypoint. */
function pmssArrUpdateSupportedApps(): void
{
    foreach (pmssArrSupportedApps() as $app) {
        if ($app === 'Sonarr') { @unlink('/etc/apt/sources.list.d/sonarr.list'); @passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null'); }

        $config = pmssArrAppConfig($app);
        if ($config !== null) { pmssArrUpdate($config); }
    }
}
