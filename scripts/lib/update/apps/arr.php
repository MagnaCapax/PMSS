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
        logMessage($app.': '.$message);
    };

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
        return;
    }

    $releases = json_decode($payload, true);
    if (!is_array($releases)) {
        $log('Invalid release metadata payload');
        return;
    }

    $asset = null;
    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            continue;
        }
        foreach ($release['assets'] as $candidateAsset) {
            $name = (string) ($candidateAsset['name'] ?? '');
            if (!preg_match($config['asset_pattern'], $name, $match)) {
                continue;
            }
            $url = (string) ($candidateAsset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $asset = [$match[1], $url, $name];
            break 2;
        }
    }
    if ($asset === null) {
        $log('No suitable linux release asset found');
        return;
    }
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
    if (!@mkdir($workDir, 0755, true) && !is_dir($workDir)) {
        $log('Failed to create temporary workspace');
        return;
    }

    $archivePath = $workDir.'/'.$assetName;
    $extractPath = $workDir.'/'.$config['extract_dir'];
    if (runCommand(sprintf('curl -sSL --fail -o %s %s', escapeshellarg($archivePath), escapeshellarg($downloadUrl))) !== 0
        || !is_file($archivePath)
    ) {
        $log('Download failed; keeping existing installation');
        runCommand('rm -rf '.escapeshellarg($workDir));
        return;
    }

    if (runCommand(sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir))) !== 0
        || !is_dir($extractPath)
    ) {
        $log('Extraction failed; keeping existing installation');
        runCommand('rm -rf '.escapeshellarg($workDir));
        return;
    }

    runCommand('rm -rf '.escapeshellarg($installPath));
    runCommand(sprintf('mv %s %s', escapeshellarg($extractPath), escapeshellarg($installPath)));
    runCommand('rm -rf '.escapeshellarg($workDir));

    $log("Installed version {$latestVersion}");
}
