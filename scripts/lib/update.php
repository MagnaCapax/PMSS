<?php
/**
 * Library for PMSS Updates
 * /scripts/lib/update.php
 *
 * Contains various functions, settings, etc. for use in /scripts/util/update-step2.php.
 */

require_once __DIR__.'/rtorrentConfig.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/update/logging.php';
require_once __DIR__.'/update/apt.php';

// Cache container for os-release parsing so tests can reset it safely.
$GLOBALS['PMSS_OS_RELEASE_CACHE'] = $GLOBALS['PMSS_OS_RELEASE_CACHE'] ?? [];

/**
 * Determine which os-release file to consult (allows tests to override).
 */
function pmssOsReleasePath(): string
{
    $override = getenv('PMSS_OS_RELEASE_PATH');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return '/etc/os-release';
}

/**
 * Locate the base directory for skeleton files.
 */
function pmssSkeletonBase(): string
{
    $override = getenv('PMSS_SKEL_DIR');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }
    return '/etc/skel';
}

/**
 * Resolve a path inside the skeleton directory.
 */
function pmssSkeletonPath(string $relative): string
{
    return pmssSkeletonBase().'/'.$relative;
}

/**
 * Update a user's file from the skeleton directory.
 *
 * @param string $file The filename relative to the skeleton base and the user's home.
 * @param string $user The username whose file should be updated.
 */
function updateUserFile($file, $user) {
    // #TODO Replace delete-then-copy with an atomic safe-write helper:
    //       write to a temp file in the same directory then rename.
    //       Preserve existing mode/owner when content is unchanged.
    // #TODO Add hermetic tests covering safe-write behavior.
    if (empty($file) || empty($user) || !file_exists("/home/{$user}")) {
        echo "Invalid parameters, file: {$file} user: {$user}\n";
        return;
    }

    $sourceFile = pmssSkeletonPath($file);
    $targetFile = "/home/{$user}/" . $file;
        
    if (!file_exists($sourceFile)) {
        echo "Source file: {$file} is missing\n";
        return;
    }
    
    if (!file_exists($targetFile)) {
        copyToUserSpace($sourceFile, $targetFile, $user);
        echo "Added: {$file} for {$user}\n";
    } else {
        $sourceContent = file_get_contents($sourceFile);
        $targetContent = file_get_contents($targetFile);
        if ($sourceContent === false || $targetContent === false) {
            echo "Error reading file contents for comparison.\n";
            return;
        }
        $sourceChecksum = sha1($sourceContent);
        $targetChecksum = sha1($targetContent);
        if ($sourceChecksum !== $targetChecksum) {
            if (!unlink($targetFile)) {
                echo "Failed to remove old file: {$targetFile}\n";
                return;
            }
            copyToUserSpace($sourceFile, $targetFile, $user);
            echo "Updated: {$file} for {$user}\n";
        }
    }
}

/**
 * Copy a file to a user's home directory and adjust its permissions and ownership.
 *
 * @param string $sourceFile The source file path.
 * @param string $targetFile The target file path in the user's home directory.
 * @param string $user       The username for setting file ownership.
 *
 * @return void
 */
function copyToUserSpace($sourceFile, $targetFile, $user) {
    if (!copy($sourceFile, $targetFile)) {
        echo "Failed to copy {$sourceFile} to {$targetFile}\n";
        return;
    }
    // Set file permissions to 755.
    passthru("chmod 755 " . escapeshellarg($targetFile));
    // Change owner and group to the specified user.
    passthru("chown " . escapeshellarg($user) . ":" . escapeshellarg($user) . " " . escapeshellarg($targetFile));
}

/**
 * Update ruTorrent configuration for a given user.
 *
 * This function reads ruTorrent configuration template files,
 * replaces placeholders with user-specific paths, and writes the updated
 * configuration to the user's ruTorrent directory.
 *
 * @param string $username The username for which to update the configuration.
 * @param int    $scgiPort The SCGI port for ruTorrent configuration (currently not used).
 *
 * @return void
 */
function updateRutorrentConfig($username, $scgiPort) {
    $templateConfigPath = '/etc/seedbox/config/template.rutorrent.config';
    $templateAccessPath = '/etc/seedbox/config/template.rutorrent.access';
    
    $rutorrentConfig = file_get_contents($templateConfigPath);
    $accessIni       = file_get_contents($templateAccessPath);
    
    if ($rutorrentConfig === false || $accessIni === false) {
        echo "Failed to read ruTorrent template files.\n";
        return;
    }
    
    // Update ruTorrent configuration with user-specific values.
    $rutorrentConfig = str_replace(
        '$scgi_host = "";',
        '$scgi_host = "unix:///home/' . $username . '/.rtorrent.socket";',
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$tempDirectory = null;',
        "\$tempDirectory = '/home/{$username}/.tmp/';",
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$topDirectory = \'/\';',
        "\$topDirectory = '/home/{$username}/';",
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$log_file = \'/tmp/errors.log\';',
        "\$log_file = '/home/{$username}/www/rutorrent/errors.log';",
        $rutorrentConfig
    );
    
    $configPath = "/home/{$username}/www/rutorrent/conf/config.php";
    $accessPath = "/home/{$username}/www/rutorrent/conf/access.ini";
    
    if (file_put_contents($configPath, $rutorrentConfig) === false) {
        echo "Failed to write ruTorrent config to {$configPath}\n";
        return;
    }
    if (file_put_contents($accessPath, $accessIni) === false) {
        echo "Failed to write ruTorrent access config to {$accessPath}\n";
        return;
    }
}

/**
 * Retrieve and cache OS release data from /etc/os-release.
 *
 * @return array Parsed key-value pairs from /etc/os-release.
 */
function getOsReleaseData() {
    $path = pmssOsReleasePath();
    if (!isset($GLOBALS['PMSS_OS_RELEASE_CACHE'][$path])) {
        $parsed = @parse_ini_file($path);
        $GLOBALS['PMSS_OS_RELEASE_CACHE'][$path] = is_array($parsed) ? $parsed : [];
    }
    return $GLOBALS['PMSS_OS_RELEASE_CACHE'][$path];
}

/**
 * Get the distribution name from /etc/os-release.
 *
 * @return string The distribution ID (e.g., "ubuntu", "debian"), or an empty string if not found.
 */
function getDistroName() {
    $data = getOsReleaseData();
    return isset($data['ID']) ? $data['ID'] : '';
}

/**
 * Get the distribution version from /etc/os-release.
 *
 * Extracts and returns the numeric part of VERSION_ID.
 *
 * @return string The distribution version number, or an empty string if not found.
 */
function getDistroVersion() {
    $data = getOsReleaseData();
    if (isset($data['VERSION_ID'])) {
        if (preg_match('/^([0-9]+)/', $data['VERSION_ID'], $matches)) {
            return $matches[1];
        }
        return $data['VERSION_ID'];
    }
    return '';
}

/**
 * Reset cached os-release data so tests can inject fresh fixtures.
 */
function pmssResetOsReleaseCache(): void
{
    $path = pmssOsReleasePath();
    unset($GLOBALS['PMSS_OS_RELEASE_CACHE'][$path]);
}

/**
 * Get the distribution codename from /etc/os-release when available.
 *
 * @return string Lowercase codename (e.g., "bullseye") or an empty string.
 */
function getDistroCodename(): string
{
    $data = getOsReleaseData();
    if (!empty($data['VERSION_CODENAME'])) {
        return strtolower(trim($data['VERSION_CODENAME']));
    }
    return '';
}

/**
 * Retrieve current PMSS version from the configured version file.
 *
 * @param string $versionFile Path to the version file.
 *
 * @return string The version string or "unknown" if not found.
 */
function getPmssVersion($versionFile = '/etc/seedbox/config/version') {
    if (file_exists($versionFile) && filesize($versionFile) > 0) {
        return trim(file_get_contents($versionFile));
    }
    return 'unknown';
}

// Backwards-compatible wrappers for legacy helper names.
if (!function_exists('loadRepoTemplate')) {
    function loadRepoTemplate(string $codename, ?callable $logger = null): string
    {
        return pmssLoadRepoTemplate($codename, $logger);
    }
}

if (!function_exists('safeWriteSources')) {
    function safeWriteSources(string $content, string $label, ?callable $logger = null): bool
    {
        return pmssSafeWriteSources($content, $label, $logger);
    }
}

if (!function_exists('updateAptSources')) {
    function updateAptSources(string $distroName, int $distroVersion, string $currentHash, array $repos, ?callable $logger = null): void
    {
        pmssUpdateAptSources($distroName, $distroVersion, $currentHash, $repos, $logger);
    }
}

/** Generate /etc/motd using the template and system details */
function generateMotd(): void {
    $motdTemplatePath = getenv('PMSS_MOTD_TEMPLATE_PATH');
    if ($motdTemplatePath === false || $motdTemplatePath === '') {
        $motdTemplatePath = '/etc/seedbox/config/template.motd';
    }
    $motdOutputPath = getenv('PMSS_MOTD_OUTPUT_PATH');
    if ($motdOutputPath === false || $motdOutputPath === '') {
        $motdOutputPath = '/etc/motd';
    }
    $motdTemplate     = @file_get_contents($motdTemplatePath);
    if ($motdTemplate === false) return;

    $serverHostname = trim(file_get_contents('/etc/hostname'));
    $serverIp       = gethostbyname($serverHostname);
    $cpuInfo        = trim(shell_exec("lscpu | grep 'Model name:' | sed 's/Model name:\\s*//'"));
    $ramInfo        = trim(shell_exec("free -h | awk '/^Mem:/ { print \$2 }'"));
    $storageInfo    = trim(shell_exec("df -h /home | awk 'NR==2 {print \$2}'"));

    $pmssVersion = getPmssVersion();
    $runtimeDir = getenv('PMSS_RUNTIME_DIR');
    if ($runtimeDir === false || $runtimeDir === '') {
        $runtimeDir = '/var/run/pmss';
    }
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0770, true);
    }
    $versionCache = rtrim($runtimeDir, '/').'/version';
    file_put_contents($versionCache, $pmssVersion);
    $runtimeVersion = trim(@file_get_contents($versionCache));
    $updateDate = file_exists('/var/run/pmss/updated') ? trim(file_get_contents('/var/run/pmss/updated')) : 'not set';
    $aptStampFile = '/var/lib/apt/periodic/update-success-stamp';
    $aptLastUpdate = file_exists($aptStampFile) ? trim(shell_exec("stat -c '%y' ".escapeshellarg($aptStampFile))) : 'Not available';
    $uptime = trim(shell_exec('uptime -p'));
    $kernelVersion = trim(shell_exec('uname -r'));
    $netSpeedRaw = shell_exec("ethtool eth0 2>/dev/null | grep 'Speed:'");
    if ($netSpeedRaw && preg_match('/Speed:\s+(\S+)/', $netSpeedRaw, $m)) {
        $networkSpeed = $m[1];
    } else {
        $networkSpeed = 'N/A';
    }

    $colorize = static function (string $text, string $color): string {
        return "\e[{$color}m{$text}\e[0m";
    };

    $serviceStatus = static function (string $service, ?string $configPath, string $name) use ($colorize): string {
        if ($configPath !== null && !file_exists($configPath)) {
            return $colorize('not configured', '33');
        }
        if (!is_dir('/run/systemd/system')) {
            return $colorize('unknown', '33');
        }
        exec('systemctl is-active --quiet '.escapeshellarg($service), $out, $activeRc);
        if ($activeRc === 0) {
            return $colorize('active', '32');
        }
        exec('systemctl is-enabled --quiet '.escapeshellarg($service), $out, $enabledRc);
        if ($enabledRc !== 0) {
            return $colorize('disabled', '33');
        }
        return $colorize('inactive', '31');
    };

    $wireguardStatus = $serviceStatus('wg-quick@wg0', '/etc/wireguard/wg0.conf', 'WireGuard');
    $openvpnStatus = $serviceStatus('openvpn@openvpn', '/etc/openvpn/openvpn.conf', 'OpenVPN');

    $replacements = [
        '%HOSTNAME%'        => $serverHostname,
        '%SERVER_IP%'       => $serverIp,
        '%SERVER_CPU%'      => $cpuInfo,
        '%SERVER_RAM%'      => $ramInfo,
        '%SERVER_STORAGE%'  => $storageInfo,
        '%PMSS_VERSION%'    => $pmssVersion,
        '%RUN_VERSION%'     => $runtimeVersion,
        '%UPDATE_DATE%'     => $updateDate,
        '%APT_LAST_UPDATE%' => $aptLastUpdate,
        '%UPTIME%'          => $uptime,
        '%KERNEL_VERSION%'  => $kernelVersion,
        '%NETWORK_SPEED%'   => $networkSpeed,
        '%WIREGUARD_STATUS%' => $wireguardStatus,
        '%OPENVPN_STATUS%'   => $openvpnStatus,
    ];

    foreach ($replacements as $p => $v) {
        $motdTemplate = str_replace($p, $v, $motdTemplate);
    }
    file_put_contents($motdOutputPath, $motdTemplate);
}
