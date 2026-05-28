<?php
/**
 * Per-user lighttpd configuration helpers and application helper.
 *
 * Extracted from scripts/util/userConfigLighttpd.php to keep the entrypoint
 * small while preserving output, ordering, and on-disk effects.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../systemdSliceProperties.php';
require_once __DIR__.'/userFileWrite.php';
require_once __DIR__.'/configRender.php';
require_once __DIR__.'/delugeWebConf.php';
require_once __DIR__.'/proxyFragments.php';
require_once __DIR__.'/resourcePlan.php';
require_once __DIR__.'/userDirectoriesPrepare.php';

function pmssUserConfigLighttpdConfigureUser(
    string $thisUser,
    string $portsDirectory,
    $template,
    bool $deflateEnabled,
    array $policyDefaults
): void
{
    if (!pmssValidateUsername($thisUser)) {
        fwrite(STDERR, "Skipping invalid username: ".substr($thisUser, 0, 20)."\n");
        return;
    }

    $homeDir = "/home/{$thisUser}";
    if (!pmssShouldConfigureLighttpdForHome($homeDir)) {
        return;
    }

    $portFile = "{$portsDirectory}/lighttpd-{$thisUser}";
    $serverPort = file_exists($portFile)
        ? pmssReadRegularFileInt($portFile)
        : (int) trim((string) shell_exec('/scripts/util/portManager.php assign '.escapeshellarg($thisUser).' lighttpd'));

    // Prepare directories and defaults.
    if (!pmssPrepareLighttpdUserDirectories($thisUser, $homeDir, $deflateEnabled)) {
        fwrite(STDERR, "[user:{$thisUser}] lighttpd directory preparation failed; skipping\n");
        return;
    }
    pmssEnsureWebdavLockDatabase($thisUser, $homeDir);
    $customDir = $homeDir.'/.lighttpd/custom.d';

    $proxyPorts = pmssLighttpdProxyPortsEnsure([
        'rclone' => $homeDir.'/.rclonePort',
        'qbittorrent' => $homeDir.'/.qbittorrentPort',
    ]);
    $rclonePort = $proxyPorts['rclone'];
    $qbittorrentPort = $proxyPorts['qbittorrent'];

    // PMSS-managed proxy fragments under ~/.lighttpd/custom.d/
    foreach ([
        'rclone' => $rclonePort,
        'qbittorrent' => $qbittorrentPort,
    ] as $proxyName => $proxyPort) {
        pmssLighttpdWriteManagedProxyFragment($proxyName, $thisUser, $proxyPort, "{$customDir}/pmss-{$proxyName}.conf");
    }

    // Optional Invidious proxy wiring: publish both public and private URLs
    // only when the user or installer pins a local port explicitly.
    $invidiousPort = pmssReadRegularFileInt($homeDir.'/.invidiousPort');
    $invidiousConfPath = $customDir.'/pmss-invidious.conf';
    if ($invidiousPort >= 1024 && $invidiousPort <= 65535) {
        pmssLighttpdWriteManagedProxyFragment('invidious', $thisUser, $invidiousPort, $invidiousConfPath);
    } elseif (is_file($invidiousConfPath) || is_link($invidiousConfPath)) {
        @unlink($invidiousConfPath);
    }

    // Deluge: generate a per-user proxy fragment under ~/.lighttpd/custom.d/
    // so nginx stays a lightweight reverse proxy.
    $delugeWebPort = pmssLighttpdDelugeWebPortResolve($thisUser, $homeDir);
    if ($delugeWebPort !== null) {
        pmssLighttpdWriteManagedProxyFragment('deluge', $thisUser, $delugeWebPort, $customDir.'/pmss-deluge.conf');
    }

    $props = pmssReadUserSlicePropertiesByUsername(
        $thisUser,
        ['MemoryHigh', 'MemoryMax', 'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota']
    );
    $resources = pmssLighttpdResourcePlan($props, $policyDefaults);
    $thisUserConfig = pmssLighttpdRenderUserConfig($template, $thisUser, $serverPort, $rclonePort, $qbittorrentPort, $resources);
    if (!pmssWriteUserFile($homeDir.'/.lighttpd.conf', $thisUserConfig, $thisUser, 0741)) {
        fwrite(STDERR, "[user:{$thisUser}] Failed to write .lighttpd.conf; skipping user\n");
        return;
    }

    $phpIniFailure = '';
    if (!pmssLighttpdSyncPhpIni($homeDir.'/.lighttpd/php.ini', $thisUser, $resources['memoryLimit'], $phpIniFailure)) {
        $message = $phpIniFailure === 'unsafe'
            ? 'Refusing to operate on unsafe php.ini path'
            : ($phpIniFailure === 'seed' ? 'Failed to seed php.ini' : 'Failed to update php.ini');
        fwrite(STDERR, "[user:{$thisUser}] {$message}; skipping user\n");
        return;
    }

    echo sprintf(
        "[user:%s] lighttpd configured (port=%d, php_memory=%dM, max-procs=%d, children=%d, cpu-quota=%d%%)\n",
        $thisUser,
        $serverPort,
        $resources['memoryLimit'],
        $resources['maxProcs'],
        $resources['children'],
        $resources['cpuQuotaPercent']
    );
}
