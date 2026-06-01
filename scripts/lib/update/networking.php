<?php
/**
 * Networking helpers for update-step2.
 *
 * Template mapping:
 *   - `/etc/seedbox/config/template.network` seeds the user-editable
 *     `/etc/seedbox/config/network` baseline when the user config is missing.
 *   - `setupNetwork.php` consumes `/etc/seedbox/config/template.fireqos` and
 *     replaces placeholders (`##IFACE##`, `##LINK##`, `##USERMATCHES##`) based on
 *     the values returned by `networkLoadConfig()`.
 *   - Local network CIDRs from `networkLoadLocalnets()` populate
 *     `##LOCALNETWORK` blocks so FireQOS exempts internal traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/managedPath.php';

/**
 * Seed the default network configuration file when missing.
 */
function pmssEnsureNetworkTemplate(?callable $logger = null): void
{
    $log  = $logger ?: 'logMessage';
    $path = pmssResolvePathFromEnv('PMSS_NETWORK_CONFIG', '/etc/seedbox/config/network');
    if (file_exists($path)) {
        return;
    }

    $configDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $templatePath = $configDir.'/template.network';
    $template = @file_get_contents($templatePath);
    if (!is_string($template) || trim($template) === '') {
        $log('[WARN] Network configuration template missing: '.$templatePath);
        return;
    }
    if (substr($template, -1) !== "\n") {
        $template .= PHP_EOL;
    }

    if (!pmssWriteManagedPathFile($path, $template, 'network configuration', $log)) {
        return;
    }
    $log('Created default network configuration');
}
