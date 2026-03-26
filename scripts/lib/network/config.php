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
    $path = pmssResolvePathFromEnv('PMSS_LOCALNET_FILE', '/etc/seedbox/config/localnet');
    if (!file_exists($path)) {
        $hostname = getenv('PMSS_HOSTNAME');
        if (!is_string($hostname) || trim($hostname) === '') {
            $hostname = function_exists('gethostname') ? (string) @gethostname() : (string) php_uname('n');
        }

        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || preg_match('/(^|\\.)pulsedmedia\\.com$/', $hostname) !== 1) {
            return [];
        }

        $default = ['185.148.0.0/22'];
        file_put_contents($path, implode("\n", $default)."\n");
        return $default;
    }

    $cfg = trim((string) file_get_contents($path));
    return $cfg === '' ? [] : preg_split('/\r?\n/', $cfg, -1, PREG_SPLIT_NO_EMPTY);
}
