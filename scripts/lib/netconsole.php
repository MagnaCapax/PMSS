<?php
/**
 * Optional netconsole configuration helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/update/runtime/commands.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';

/**
 * Parse a netconsole spec and extract the interface, target IP, and target MAC.
 */
function pmssNetconsoleTargetFromSpec(string $spec): ?array
{
    if (!preg_match('~^[^,]*/([^,/@]+),[^@,]*@([^/\s,]+)/(([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2})$~', trim($spec), $matches)
        || filter_var($matches[2], FILTER_VALIDATE_IP) === false) {
        return null;
    }

    return [
        'interface' => $matches[1],
        'targetIp' => $matches[2],
        'targetMac' => strtolower($matches[3]),
    ];
}

/**
 * Apply optional netconsole configuration when `/etc/seedbox/config/netconsole` exists.
 */
function pmssNetconsoleConfigure(callable $log, ?callable $runner = null): void
{
    $runner = $runner ?: 'runStep';
    $configPath = pmssResolvePathFromEnv('PMSS_NETCONSOLE_CONFIG_PATH', '/etc/seedbox/config/netconsole');
    $optionsPath = pmssResolvePathFromEnv('PMSS_NETCONSOLE_MODPROBE_PATH', '/etc/modprobe.d/netconsole.conf');
    $modulesLoadPath = pmssResolvePathFromEnv('PMSS_NETCONSOLE_MODULES_LOAD_PATH', '/etc/modules-load.d/pmss-netconsole.conf');
    $spec = is_file($configPath) ? trim((string) @file_get_contents($configPath)) : '';

    if ($spec === '') {
        $log('[SKIP] No netconsole configuration at '.$configPath);
        return;
    }

    if (($target = pmssNetconsoleTargetFromSpec($spec)) === null) {
        $log('[WARN] Invalid netconsole syntax in '.$configPath);
        return;
    }

    if ((int) $runner(
        'Verifying netconsole target reachability',
        'bash -lc '.escapeshellarg(
            (strpos($target['targetIp'], ':') === false ? 'ping' : 'ping -6')
            .' -c 1 -W 1 -I '.escapeshellarg($target['interface']).' '.escapeshellarg($target['targetIp']).' >/dev/null 2>&1 || true; '
            .'ip neigh show to '.escapeshellarg($target['targetIp']).' dev '.escapeshellarg($target['interface'])
            .' | grep -Fqi '.escapeshellarg($target['targetMac'])
        )
    ) !== 0) {
        $log('[WARN] Netconsole target '.$target['targetIp'].'/'.$target['targetMac'].' is not reachable via '.$target['interface']);
        return;
    }

    $changed = false;
    foreach ([$optionsPath => "options netconsole netconsole={$spec}\n", $modulesLoadPath => "netconsole\n"] as $path => $body) {
        $dir = dirname($path);
        if (!pmssDirEnsureExists($dir, 0755)) {
            $log('[WARN] Failed to create netconsole directory '.$dir);
            continue;
        }
        if (is_file($path) && (string) @file_get_contents($path) === $body) {
            continue;
        }
        if (!pmssAtomicWriteFile($path, $body, 0644)) {
            $log('[WARN] Failed to write netconsole file '.$path);
            continue;
        }
        $log('Updated '.$path);
        $changed = true;
    }
    if (!$changed && ((($override = getenv('PMSS_NETCONSOLE_MODULE_LOADED')) !== false && $override !== '') ? $override === '1' : is_dir('/sys/module/netconsole'))) {
        $log('[SKIP] netconsole already configured and loaded');
        return;
    }

    if ((int) $runner(
        'Loading netconsole kernel module',
        'bash -lc '.escapeshellarg('modprobe -r netconsole >/dev/null 2>&1 || true; modprobe netconsole')
    ) !== 0) {
        $log('[WARN] Failed to load netconsole kernel module');
    }
}
