<?php
/**
 * Optional netconsole configuration helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/update/runtime/commands.php';

/**
 * Parse a netconsole spec and extract the interface, target IP, and target MAC.
 */
function pmssNetconsoleTargetFromSpec(string $spec): ?array
{
    if (!preg_match('~^[^,]*/([^,/@]+),[^@,]*@([^/\s,]+)/(([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2})$~', trim($spec), $matches)) {
        return null;
    }

    $targetIp = trim($matches[2]);
    if (filter_var($targetIp, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    return [
        'interface' => $matches[1],
        'targetIp' => $targetIp,
        'targetMac' => strtolower($matches[3]),
    ];
}

/**
 * Detect whether the kernel module is already loaded.
 */
function pmssNetconsoleModuleLoaded(): bool
{
    $override = getenv('PMSS_NETCONSOLE_MODULE_LOADED');
    return ($override !== false && $override !== '') ? $override === '1' : is_dir('/sys/module/netconsole');
}

/**
 * Write a file only when content changes.
 */
function pmssNetconsoleWriteIfChanged(string $path, string $body, callable $log): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $log('[WARN] Failed to create netconsole directory '.$dir);
        return false;
    }

    $current = is_file($path) ? (string) @file_get_contents($path) : '';
    if ($current === $body) {
        return false;
    }

    if (@file_put_contents($path, $body) === false) {
        $log('[WARN] Failed to write netconsole file '.$path);
        return false;
    }

    @chmod($path, 0644);
    $log('Updated '.$path);
    return true;
}

/**
 * Verify that the configured target MAC is reachable via the configured link.
 */
function pmssNetconsoleTargetIsReachable(array $target, callable $log, ?callable $runner = null): bool
{
    $runner = $runner ?: 'runStep';
    $ping = strpos($target['targetIp'], ':') === false ? 'ping' : 'ping -6';
    $command = 'bash -lc '.escapeshellarg(
        $ping.' -c 1 -W 1 -I '.escapeshellarg($target['interface']).' '.escapeshellarg($target['targetIp']).' >/dev/null 2>&1 || true; '
        .'ip neigh show to '.escapeshellarg($target['targetIp']).' dev '.escapeshellarg($target['interface'])
        .' | grep -Fqi '.escapeshellarg($target['targetMac'])
    );

    if ((int) $runner('Verifying netconsole target reachability', $command) === 0) {
        return true;
    }

    $log('[WARN] Netconsole target '.$target['targetIp'].'/'.$target['targetMac'].' is not reachable via '.$target['interface']);
    return false;
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

    if (!pmssNetconsoleTargetIsReachable($target, $log, $runner)) {
        return;
    }

    $changed = pmssNetconsoleWriteIfChanged($optionsPath, "options netconsole netconsole={$spec}\n", $log);
    $changed = pmssNetconsoleWriteIfChanged($modulesLoadPath, "netconsole\n", $log) || $changed;
    if (!$changed && pmssNetconsoleModuleLoaded()) {
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
