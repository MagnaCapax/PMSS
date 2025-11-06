<?php
/**
 * Shared helpers for *ARR (Sonarr/Radarr) style application updaters.
 */

/**
 * Orchestrate the full update flow for a Starr-family application.
 *
 * Expected keys in the configuration array:
 *  - app: Human-readable application label (e.g. "Radarr")
 *  - version_record: File path where the installed version is persisted
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

    $recordPath  = $config['version_record'];
    $installPath = $config['install_path'];

    $currentVersion = trim((string)@file_get_contents($recordPath));
    if ($currentVersion === $latestVersion && is_dir($installPath)) {
        $log("Already at {$latestVersion}, skipping update");
        return;
    }

    $workDir = pmssArrCreateWorkspace($app, $log);
    if ($workDir === null) {
        return;
    }

    $archivePath = $workDir.'/'.$assetName;
    if (!pmssArrDownload($downloadUrl, $archivePath, $log)) {
        pmssArrCleanup($workDir);
        return;
    }

    if (!pmssArrExtract($archivePath, $workDir, $config['extract_dir'], $log)) {
        pmssArrCleanup($workDir);
        return;
    }

    runCommand('rm -rf '.escapeshellarg($installPath));
    runCommand(sprintf('mv %s %s', escapeshellarg($workDir.'/'.$config['extract_dir']), escapeshellarg($installPath)));
    pmssArrCleanup($workDir);

    if (pmssArrPersistVersion($recordPath, $latestVersion)) {
        $log("Installed version {$latestVersion}");
    } else {
        $log('Installed, but failed to persist version metadata');
    }
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
 * Persist the installed version and ensure the directory exists.
 */
function pmssArrPersistVersion(string $recordPath, string $version): bool
{
    $dir = dirname($recordPath);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    return @file_put_contents($recordPath, $version.PHP_EOL) !== false;
}

/**
 * Delete the temporary workspace.
 */
function pmssArrCleanup(string $workDir): void
{
    runCommand('rm -rf '.escapeshellarg($workDir));
}

/**
 * Emit a structured log line for Starr installers.
 */
function pmssArrLog(string $app, string $message): void
{
    logMessage($app.': '.$message);
}
