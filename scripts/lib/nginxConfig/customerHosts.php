<?php
/**
 * Customer stable-host nginx helpers.
 *
 * The remote API adapter is intentionally a stub until pulsedmedia.com exposes
 * a per-server hostname-to-local-user slice. Rendering accepts an internal map
 * so the serving layer remains testable without inventing the endpoint shape.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../nginxUserHosts.php';
require_once __DIR__.'/../user/customerHostDocroot.php';

function pmssNginxCustomerHostEndpointExtensionRequirement(): string
{
    return 'pulsedmedia.com/remote/mcxData-api.php needs an authenticated per-server slice for the requesting seedbox: each row must provide an external hostname FQDN and the local PMSS username to serve on this server.';
}

function pmssNginxCustomerHostMapLoad(string $serverHostname): array
{
    unset($serverHostname);

    return [
        'loaded' => false,
        'hostsByUser' => [],
        'message' => pmssNginxCustomerHostEndpointExtensionRequirement(),
    ];
}

function pmssNginxCustomerHostMapLoaded(array $ctx): bool
{
    return !empty($ctx['customerHostMapLoaded']);
}

/**
 * @return string[]
 */
function pmssNginxCustomerHostnamesNormalize(array $hostnames): array
{
    $normalized = [];
    foreach ($hostnames as $hostname) {
        if (!is_string($hostname)) {
            continue;
        }
        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || strpos($hostname, '*') !== false || !pmssNginxUserHostIsValidFqdn($hostname)) {
            continue;
        }
        $normalized[$hostname] = $hostname;
    }

    return array_values($normalized);
}

function pmssNginxCustomerHostConfigPath(array $ctx, string $user): string
{
    return rtrim((string) ($ctx['subdomainConfigDir'] ?? '/etc/nginx/conf.d'), '/').'/pmss-customer-host-'.$user.'.conf';
}

function pmssNginxCustomerHostTemplateRender(string $template, string $user, array $hostnames, int $serverPort, string $docrootSubdir): string
{
    return strtr($template, [
        '##username##' => $user,
        '##serverNames##' => implode(' ', pmssNginxCustomerHostnamesNormalize($hostnames)),
        '##serverPort##' => (string) $serverPort,
        '##docrootSubdir##' => $docrootSubdir,
        '##lighttpdAlias##' => PMSS_CUSTOMER_HOST_LIGHTTPD_ALIAS,
    ]);
}
