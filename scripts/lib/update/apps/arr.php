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
function pmssArrUpdate(array $config): void
{
    $app = $config['app'];
    $log = static function (string $message) use ($app): void {
        pmssArrLog($app, $message);
    };

    $asset = pmssArrResolveAsset($config, $log);
    if ($asset === null) {
        return;
    }
    [$latestVersion, $downloadUrl, $assetName] = $asset;

    $installPath = $config['install_path'];

    $currentVersion = pmssArrDetectInstalledVersion($installPath, $app);
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

    $workDir = pmssArrCreateWorkspace($app, $log);
    if ($workDir === null) {
        return;
    }

    $archivePath = $workDir.'/'.$assetName;
    $extractPath = $workDir.'/'.$config['extract_dir'];
    $prepared = pmssArrDownload($downloadUrl, $archivePath, $log)
        && pmssArrExtract($archivePath, $workDir, $config['extract_dir'], $log);
    if (!$prepared) {
        pmssArrCleanup($workDir);
        return;
    }

    runCommand('rm -rf '.escapeshellarg($installPath));
    runCommand(sprintf('mv %s %s', escapeshellarg($extractPath), escapeshellarg($installPath)));
    pmssArrCleanup($workDir);

    $log("Installed version {$latestVersion}");
}

/**
 * Resolve the latest linux asset that matches the configured pattern.
 */
function pmssArrResolveAsset(array $config, callable $log): ?array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: '.($config['user_agent'] ?? 'PMSS-ARR'),
    ];
    $context = stream_context_create([
        'http' => [
            'header'  => $headers,
            'timeout' => 15,
        ],
    ]);

    $payload = @file_get_contents($config['releases_url'], false, $context);
    if ($payload === false) {
        $log('Unable to fetch release metadata (network issue?)');
        return null;
    }

    $releases = json_decode($payload, true);
    if (!is_array($releases)) {
        $log('Invalid release metadata payload');
        return null;
    }

    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            continue;
        }
        foreach ($release['assets'] as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (!preg_match($config['asset_pattern'], $name, $match)) {
                continue;
            }
            $version = $match[1];
            $url = (string)($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            return [$version, $url, $name];
        }
    }

    $log('No suitable linux release asset found');
    return null;
}

/**
 * Create a temporary working directory for archive extraction.
 */
function pmssArrCreateWorkspace(string $app, callable $log): ?string
{
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $suffix = uniqid();
    }
    $workDir = sys_get_temp_dir().'/'.strtolower($app).'-'.$suffix;
    if (@mkdir($workDir, 0755, true)) {
        return $workDir;
    }
    if (is_dir($workDir)) {
        return $workDir;
    }
    $log('Failed to create temporary workspace');
    return null;
}

/**
 * Download the release asset to the given path.
 */
function pmssArrDownload(string $url, string $targetPath, callable $log): bool
{
    $cmd = sprintf('curl -sSL --fail -o %s %s', escapeshellarg($targetPath), escapeshellarg($url));
    if (runCommand($cmd) !== 0 || !is_file($targetPath)) {
        $log('Download failed; keeping existing installation');
        return false;
    }
    return true;
}

/**
 * Extract the archive and confirm the expected directory exists.
 */
function pmssArrExtract(string $archivePath, string $workDir, string $expectedDir, callable $log): bool
{
    $extractCmd = sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir));
    if (runCommand($extractCmd) !== 0 || !is_dir($workDir.'/'.$expectedDir)) {
        $log('Extraction failed; keeping existing installation');
        return false;
    }
    return true;
}

/**
 * Extract a semantic version string from the provided text.
 */
function pmssArrExtractVersionFromString(?string $payload): ?string
{
    if (is_string($payload) && $payload !== '' && preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $payload, $match)) {
        return $match[1];
    }
    return null;
}

/**
 * Delete the temporary workspace.
 */
function pmssArrCleanup(string $workDir): void
{
    runCommand('rm -rf '.escapeshellarg($workDir));
}

/**
 * Detect an installed version without relying on out-of-band state files.
 */
function pmssArrDetectInstalledVersion(string $installPath, string $app): ?string
{
    $versionFiles = [
        $installPath.'/version.txt',
        $installPath.'/VERSION',
    ];
    foreach ($versionFiles as $file) {
        if (!is_file($file)) {
            continue;
        }
        $version = pmssArrExtractVersionFromString(@file_get_contents($file));
        if ($version !== null) {
            return $version;
        }
    }

    $binaries = [
        $installPath.'/'.$app,
        $installPath.'/'.strtolower($app),
        $installPath.'/'.$app.'.exe',
    ];
    foreach ($binaries as $binary) {
        if (!is_executable($binary)) {
            continue;
        }
        $commands = [
            escapeshellarg($binary).' --version 2>/dev/null',
            escapeshellarg($binary).' -v 2>/dev/null',
        ];
        foreach ($commands as $command) {
            $output = @shell_exec($command);
            $version = pmssArrExtractVersionFromString($output);
            if ($version !== null) {
                return $version;
            }
        }
    }

    return null;
}

/**
 * Emit a structured log line for Starr installers.
 */
function pmssArrLog(string $app, string $message): void
{
    logMessage($app.': '.$message);
}
