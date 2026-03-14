<?php
/**
 * Network configuration helpers shared by setup scripts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

function networkLoadConfig(): array
{
    $config = file_exists($path = pmssResolvePathFromEnv('PMSS_NETWORK_CONFIG', '/etc/seedbox/config/network'))
        ? include $path
        : null;
    return is_array($config) ? $config : [];
}

function networkLoadLocalnets(): array
{
    $default = ['185.148.0.0/22']; // #TODO Refactor hardcoded value
    $path = pmssResolvePathFromEnv('PMSS_LOCALNET_FILE', '/etc/seedbox/config/localnet');
    if (!file_exists($path)) {
        file_put_contents($path, implode("\n", $default) . "\n");
        return $default;
    }

    $cfg = trim((string) file_get_contents($path));
    return $cfg === '' ? $default : array_filter(preg_split('/\r?\n/', $cfg) ?: [], 'strlen');
}
