<?php
/** Customer Info statistics: statsStatus. Loaded by statsHelpers.php. */

/** Return active when any expected customer-readable network interface exists. */
function pmssStatsNetworkInterfaceStatus(array $interfaceNames, string $interfacesRoot = '/sys/class/net'): string
{
    $interfacesRoot = rtrim($interfacesRoot, '/');
    foreach ($interfaceNames as $interfaceName) {
        if (!is_string($interfaceName) || $interfaceName === '') {
            continue;
        }
        if (@is_dir($interfacesRoot.'/'.$interfaceName)) {
            return 'active';
        }
    }

    return 'inactive';
}

/**
 * Read service, app, and Docker states for the compact server-info grid.
 *
 * @return array{wgStatus:string,ovpnStatus:string,apps:array<string,string>,dockerInactiveNote:string}
 */
function pmssStatsStatusModelBuild(?string $uid, ?bool $dockerEnabledPolicy, ?callable $runner = null, array $overrides = []): array
{
    $runner = $runner ?? 'pmssInfoShellExec';
    $interfacesRoot = is_string($overrides['network_interfaces_root'] ?? null)
        && $overrides['network_interfaces_root'] !== ''
            ? $overrides['network_interfaces_root']
            : '/sys/class/net';

    $processNeedles = array('rTorrent' => 'rtorrent', 'qBittorrent' => 'qbittorrent-nox', 'Deluge' => 'deluged', 'rclone' => 'rclone');
    $psResult = $runner('ps aux | grep -E "(rtorrent|qbittorrent-nox|deluged|rclone)" | grep -v grep', 'App status');
    $psOutput = $psResult['error'] === null ? (string) $psResult['output'] : null;
    $apps = array();
    foreach ($processNeedles as $name => $needle) {
        $apps[$name] = $psOutput === null ? 'error' : (stripos($psOutput, $needle) !== false ? 'active' : 'stopped');
    }
    foreach (array_keys(require __DIR__.'/toggleAppCatalog.php') as $name) {
        $definitions = function_exists('pmssCustomerManagedAppDefinitions') ? pmssCustomerManagedAppDefinitions() : array();
        $apps[$name] = function_exists('pmssCustomerNativePidsRead') && isset($definitions[$name])
            && pmssCustomerNativePidsRead($definitions[$name]['binaries']) !== array() ? 'active' : 'stopped';
    }

    $dockerStatus = 'error';
    if ($uid !== null && file_exists('/run/user/'.$uid.'/docker.sock')) {
        $dockerStatus = 'active';
    } elseif ($uid !== null) {
        $dockerResult = $runner('docker ps --no-trunc 2>&1', 'Docker status');
        $dockerStatus = $dockerResult['error'] !== null
            ? 'error'
            : (strpos((string) $dockerResult['output'], 'docker ps') === false && trim((string) $dockerResult['output']) === '' ? 'active' : 'inactive');
    }
    $apps['Docker'] = $dockerStatus;

    return array(
        'wgStatus' => pmssStatsNetworkInterfaceStatus(array('wg0'), $interfacesRoot),
        'ovpnStatus' => pmssStatsNetworkInterfaceStatus(array('tun0'), $interfacesRoot),
        'apps' => $apps,
        'dockerInactiveNote' => pmssStatsDockerInactiveNote($dockerStatus, $dockerEnabledPolicy),
    );
}

/**
 * Build the Docker inactive note shown beside the Docker app status.
 */
function pmssStatsDockerInactiveNote(
    string $dockerStatus,
    ?bool $dockerEnabledPolicy,
    string $osReleasePath = '/etc/os-release',
    string $debianVersionPath = '/etc/debian_version',
    string $cmdlinePath = '/proc/cmdline'
): string {
    if ($dockerStatus !== 'inactive') {
        return '';
    }

    $cmdline = (string) (pmssCustomerFileRead($cmdlinePath, true) ?? '');
    if (strpos($cmdline, 'unified_cgroup_hierarchy=0') !== false) {
        if ($dockerEnabledPolicy === false) {
            return ' (Docker is available but currently disabled by policy. Contact support if it should be enabled.)';
        }

        return $dockerEnabledPolicy === true
            ? ' (Docker is enabled by policy but not currently running. Contact support if it should be restarted.)'
            : ' (Docker is available but not currently running. Contact support if it should be enabled for this account.)';
    }

    $debianLabel = 'Debian';
    $osRelease = @parse_ini_file($osReleasePath);
    if (is_array($osRelease)) {
        $versionId = trim((string) ($osRelease['VERSION_ID'] ?? ''));
        if ($versionId !== '') {
            $debianLabel = preg_match('/^([0-9]+)/', $versionId, $matches) === 1
                ? 'Debian '.$matches[1]
                : 'Debian '.$versionId;
        }
    }
    if ($debianLabel === 'Debian') {
        $debianVersion = (string) (pmssCustomerTrimmedFileRead($debianVersionPath, true) ?? '');
        if (preg_match('/^([0-9]+)/', $debianVersion, $matches) === 1) {
            $debianLabel = 'Debian '.$matches[1];
        }
    }

    return sprintf(
        ' (%s: User bus restricted. Requires `systemd.unified_cgroup_hierarchy=0` in GRUB. Contact support.)',
        $debianLabel
    );
}

/**
 * Build an inline enable/disable control next to a customer-managed app status.
 */
function pmssStatsAppToggleButtonHtmlBuild(string $appName, array $definitions): string
{
    if (!is_array($definitions[$appName] ?? null)) {
        return '';
    }

    $definition = $definitions[$appName];
    $endpoint = (string) ($definition['endpoint'] ?? '');
    $enableFile = (string) ($definition['enable'] ?? '');
    $endpointPath = __DIR__.'/'.explode('?', $endpoint, 2)[0];
    if ($endpoint === '' || $enableFile === '' || !is_file($endpointPath) || is_link($endpointPath)) {
        return '';
    }

    // #TODO(2026-11-01): Remove the pre-Configure fallback (ADR 0103 removal plan).
    if (is_file(__DIR__.'/configure.php') && is_file(__DIR__.'/configureSettings.php')) {
        return '<a href="configure.php#apps">Manage in Configure</a>';
    }

    $enabled = is_file($enableFile);
    $action = $enabled ? 'disable' : 'start';
    $label = $enabled ? 'Disable' : 'Enable';
    $class = $enabled ? 'pmss-app-toggle-disable' : 'pmss-app-toggle-enable';

    return '<button type="button" class="pmss-app-toggle '.$class.'"'
        .' data-app="'.pmssCustomerHtmlAttr($appName).'"'
        .' data-endpoint="'.pmssCustomerHtmlAttr($endpoint).'"'
        .' data-action="'.pmssCustomerHtmlAttr($action).'"'
        .' aria-label="'.pmssCustomerHtmlAttr($label.' '.$appName).'"'
        .' onclick="return pmssStatsToggleApp(this);">'.$label.'</button><span class="pmss-app-toggle-feedback" aria-live="polite"></span>';
}
