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
    if (!pmssPathAbsoluteStringIsSafe($path)) {
        return false;
    }

    // The updater replaces this directory with rm+mv; never allow top-level
    // targets such as /opt, /home, or /tmp to pass config validation.
    if (count(explode('/', trim($path, '/'))) < 2) {
        return false;
    }

    return pmssPathTargetIsSafe($path, true, false);
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
    $tokenPattern = static function (array $tokens): string { return '/(?:^|[^a-z0-9])(?:'.implode('|', array_map(static function (string $token): string { return preg_quote(strtolower($token), '/'); }, $tokens)).')(?=[^a-z0-9]|$)/'; };
    $targetArchitecturePattern = $tokenPattern($targetArchitectureTokens);
    $knownArchitecturePattern = $tokenPattern($knownArchitectureTokens);
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
            $nameLower = strtolower($name);
            if (strpos($nameLower, 'musl') !== false) {
                continue; // skip musl builds: dynamic loader /lib/ld-musl-* is absent on glibc Debian -> non-executable (#489, libc filter missing post-#447)
            }
            if (preg_match($targetArchitecturePattern, $nameLower) === 1) {
                return $candidate;
            }
            if ($genericAsset === null && preg_match($knownArchitecturePattern, $nameLower) !== 1) {
                $genericAsset = $candidate;
            }
        }
    }

    return $genericAsset;
}

/**
 * Re-check activation paths immediately before replacing an installed app tree.
 */
function pmssArrReleaseActivationPathsAreSafe(string $workDir, string $workPrefix, string $extractPath, string $installPath): bool
{
    if (!pmssArrInstallPathIsSafe($installPath) || is_link($workDir) || is_link($extractPath)) {
        return false;
    }

    $workReal = function_exists('pmssPrivateTempDirRealpath')
        ? pmssPrivateTempDirRealpath($workDir, $workPrefix)
        : realpath($workDir);
    $extractReal = realpath($extractPath);
    if (!is_string($workReal) || !is_string($extractReal) || !is_dir($extractReal)) {
        return false;
    }

    return strpos($extractReal, rtrim($workReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) === 0;
}

/** Download, extract, and atomically activate one prepared ARR release asset. */
function pmssArrReleaseActivate(array $config, string $app, string $downloadUrl, string $assetName, callable $log): bool
{
    $workPrefix = strtolower($app).'-'; $workDir = pmssCreatePrivateTempDir($workPrefix);
    if ($workDir === null) { $log('Failed to create temporary workspace'); return false; }
    $archivePath = $workDir.'/'.$assetName; $extractPath = $workDir.'/'.$config['extract_dir']; $installPath = $config['install_path'];
    $steps = [
        ['Download failed; keeping existing installation', sprintf('curl -sSL --fail -o %s %s', escapeshellarg($archivePath), escapeshellarg($downloadUrl)), $archivePath, 'is_file'],
        ['Extraction failed; keeping existing installation', sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir)), $extractPath, 'is_dir'],
        ['Install parent directory missing; refusing to replace application', '', dirname($installPath), 'is_dir'],
    ];

    $ok = true; $message = '';
    foreach ($steps as $step) { if ($step[1] !== '' && runCommand($step[1]) !== 0) { $message = $step[0]; $ok = false; break; } if ($step[2] !== '' && !$step[3]($step[2])) { $message = $step[0]; $ok = false; break; } }
    if ($ok && !pmssArrReleaseActivationPathsAreSafe($workDir, $workPrefix, $extractPath, $installPath)) {
        $message = 'Unsafe activation paths; keeping existing installation';
        $ok = false;
    }
    foreach ($ok ? [
        ['Failed to remove existing installation; keeping existing installation', 'rm -rf '.escapeshellarg($installPath), '', ''],
        ['Failed to activate extracted release', sprintf('mv %s %s', escapeshellarg($extractPath), escapeshellarg($installPath)), $installPath, 'is_dir'],
    ] : [] as $step) { if ($step[1] !== '' && runCommand($step[1]) !== 0) { $message = $step[0]; $ok = false; break; } if ($step[2] !== '' && !$step[3]($step[2])) { $message = $step[0]; $ok = false; break; } }
    if ($message !== '') { $log($message); }
    pmssRemovePrivateTempDir($workDir, $workPrefix, 'Cleaning '.$app.' updater workspace', $log, static function (string $description, string $command): int { return runCommand($command); }); return $ok;
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

    if (pmssArrReleaseActivate($config, $app, $downloadUrl, $assetName, $log)) {
        if (@file_put_contents($installPath.'/version.txt', $latestVersion.PHP_EOL) === false) {
            $log('Failed to persist installed version marker');
        }
        $log("Installed version {$latestVersion}");
    }
}

/** Run every supported Servarr updater from the single app entrypoint. */
function pmssArrUpdateSupportedApps(): void
{
    foreach (PMSS_ARR_APP_BRANCHES as $app => $branchPattern) {
        if ($app === 'Sonarr') { @unlink('/etc/apt/sources.list.d/sonarr.list'); @passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null'); }

        pmssArrUpdate([
            'app' => $app,
            'install_path' => '/opt/'.$app,
            'releases_url' => 'https://api.github.com/repos/'.$app.'/'.$app.'/releases',
            'asset_pattern' => sprintf('/%s\\.(?:%s)\\.([0-9.]+).*linux.*tar\\.gz/i', preg_quote($app, '/'), $branchPattern),
            'extract_dir' => $app,
            'user_agent' => 'PMSS-'.$app,
        ]);
    }
}
