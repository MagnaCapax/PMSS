<?php
/**
 * Network configuration helpers shared by setup scripts.
 */

require_once __DIR__.'/../runtime.php';

function networkLoadConfig(): array
{
    $path = networkConfigPath();
    if (file_exists($path)) {
        $config = include $path;
        if (is_array($config)) {
            return $config;
        }
    }
    return [];
}

function networkLoadLocalnets(): array
{
    $default = ['185.148.0.0/22']; // #TODO Refactor hardcoded value
    $path = networkLocalnetPath();
    if (!file_exists($path)) {
        file_put_contents($path, implode("\n", $default) . "\n");
        return $default;
    }

    $cfg = trim((string)file_get_contents($path));
    if ($cfg === '') {
        return $default;
    }
    return array_filter(preg_split('/\r?\n/', $cfg) ?: [], 'strlen');
}

function networkConfigPath(): string
{
    return pmssResolvePathFromEnv('PMSS_NETWORK_CONFIG', '/etc/seedbox/config/network');
}

function networkLocalnetPath(): string
{
    return pmssResolvePathFromEnv('PMSS_LOCALNET_FILE', '/etc/seedbox/config/localnet');
}
