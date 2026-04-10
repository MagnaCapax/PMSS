<?php
/**
 * Shared helpers for *ARR (Sonarr/Radarr) style application updaters.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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

/** Return the expected Starr asset tokens for the current host architecture. */
function pmssArrAssetArchitectureTokens(): array
{
    $architecture = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null'));
    if ($architecture === 'arm64') {
        return ['arm64', 'aarch64'];
    }
    if ($architecture === 'armhf') {
        return ['armhf', 'armv7', 'arm'];
    }

    return ['x64', 'amd64'];
}

/**
 * Reject config values that could break shell/file boundaries.
 */
function pmssArrIsSafeConfigValue(string $value): bool
{
    return $value !== '' && preg_match('/[\r\n\0]/', $value) !== 1;
}

/**
 * Only allow one extracted top-level directory from the downloaded archive.
 */
function pmssArrExtractDirectoryIsSafe(string $extractDir): bool
{
    return pmssArrIsSafeConfigValue($extractDir)
        && $extractDir !== '.'
        && $extractDir !== '..'
        && basename($extractDir) === $extractDir;
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

    if ($config['install_path'][0] !== '/' || $config['install_path'] === '/') {
        $log('Invalid updater configuration: install_path');
        return null;
    }
    if (!pmssArrExtractDirectoryIsSafe($config['extract_dir'])) {
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

/**
 * Asset names must stay inside the temporary workspace.
 */
function pmssArrArchiveNameIsSafe(string $assetName): bool
{
    return pmssArrIsSafeConfigValue($assetName)
        && basename($assetName) === $assetName
        && preg_match('/[\\\/]/', $assetName) !== 1;
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

    $releases = json_decode($payload, true);
    if (!is_array($releases)) { $log('Invalid release metadata payload'); return; }

    $asset = null;
    $genericAsset = null;
    $targetArchitectureTokens = pmssArrAssetArchitectureTokens();
    $knownArchitectureTokens = ['x64', 'amd64', 'arm64', 'aarch64', 'armhf', 'armv7', 'arm'];
    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            continue;
        }
        foreach ($release['assets'] as $candidateAsset) {
            $name = (string) ($candidateAsset['name'] ?? '');
            if (!pmssArrArchiveNameIsSafe($name)) {
                continue;
            }
            if (!preg_match($config['asset_pattern'], $name, $match)) {
                continue;
            }
            $url = (string) ($candidateAsset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $candidate = [$match[1], $url, $name];
            if (pmssArrAssetNameHasToken($name, $targetArchitectureTokens)) {
                $asset = $candidate;
                break 2;
            }
            if ($genericAsset === null && !pmssArrAssetNameHasToken($name, $knownArchitectureTokens)) {
                $genericAsset = $candidate;
            }
        }
    }
    if ($asset === null) {
        $asset = $genericAsset;
    }
    if ($asset === null) { $log('No suitable linux release asset found'); return; }
    [$latestVersion, $downloadUrl, $assetName] = $asset;

    $installPath = $config['install_path'];

    $currentVersion = null;
    foreach ([$installPath.'/version.txt', $installPath.'/VERSION'] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $versionPayload = @file_get_contents($file);
        if (is_string($versionPayload)
            && $versionPayload !== ''
            && preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $versionPayload, $match)
        ) {
            $currentVersion = $match[1];
            break;
        }
    }
    if ($currentVersion === null) {
        foreach ([$installPath.'/'.$app, $installPath.'/'.strtolower($app), $installPath.'/'.$app.'.exe'] as $binary) {
            if (!is_executable($binary)) {
                continue;
            }
            foreach ([escapeshellarg($binary).' --version 2>/dev/null', escapeshellarg($binary).' -v 2>/dev/null'] as $command) {
                $output = @shell_exec($command);
                if (is_string($output)
                    && $output !== ''
                    && preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $output, $match)
                ) {
                    $currentVersion = $match[1];
                    break 2;
                }
            }
        }
    }
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

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $suffix = uniqid();
    }
    $workDir = sys_get_temp_dir().'/'.strtolower($app).'-'.$suffix;
    if (!@mkdir($workDir, 0755, true) && !is_dir($workDir)) { $log('Failed to create temporary workspace'); return; }

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
    if ($installed) { $log("Installed version {$latestVersion}"); }
}
