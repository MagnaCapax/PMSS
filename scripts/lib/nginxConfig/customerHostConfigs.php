<?php
/**
 * Customer stable-host nginx config rendering helpers.
 *
 * Kept separate from userConfigsGenerate.php so the per-user generator stays
 * focused on orchestration while this file owns the host-vhost side path.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/customerHosts.php';

/**
 * Refresh lighttpd config when the selected customer-host docroot changed.
 */
function pmssCreateNginxConfigRefreshLighttpdDocrootIfStale(string $user, string $homeDir, string $docrootSubdir): void
{
    $configPath = $homeDir.'/.lighttpd.conf';
    $config = pmssReadRegularFileContents($configPath);
    $marker = '# PMSS customer host docroot: '.$docrootSubdir."\n";
    if (is_string($config) && strpos($config, $marker) !== false) {
        return;
    }

    $rc = 0;
    passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($user), $rc);
    if ($rc !== 0) {
        pmssCreateNginxConfigUserLog($user, '[WARN] customer-host docroot lighttpd refresh failed (rc='.$rc.')');
    }
}

/**
 * Write generic customer-host vhosts for mcx.fi and future custom domains.
 */
function pmssCreateNginxConfigWriteCustomerHostConfig(array $ctx, string $user, array $hostnames, bool $suspended, ?int $serverPort = null, string $docrootSubdir = 'public'): bool
{
    $hostnames = pmssNginxCustomerHostnamesNormalize($hostnames);
    if ($hostnames === []) {
        return true;
    }

    $templateKey = $suspended ? 'customerHostSuspendedTemplate' : 'customerHostTemplate';
    $template = $ctx[$templateKey] ?? false;
    if (!is_string($template) || trim($template) === '') {
        return true;
    }
    if (!$suspended && $serverPort === null) {
        return false;
    }

    $config = pmssNginxCustomerHostTemplateRender($template, $user, $hostnames, (int) $serverPort, $docrootSubdir);
    return pmssCreateNginxConfigWriteFile(pmssNginxCustomerHostConfigPath($ctx, $user), $config, $user, 'customer stable-host config');
}
