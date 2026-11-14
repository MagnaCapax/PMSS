<?php
/** Ordered account-UI defaults; route policy remains gated by the host marker. */

/** Converge ports A/B in order, attempt shared pages once, then retire port C. */
function pmssNginxConfigSetupAccountUi(bool $subdomainEnabled, string $nginxSslBlock, string $subdomainConfigDir): void
{
    $pagesAttempted = false;
    foreach ([
        'pmss-pikkumies-default.conf' => [844, '/opt/pikkumies-agentboard/agentboard', 'pmssPikkumiesUiDefaultNginxBlock', 'write account UI default'],
        'pmss-pikkumies-hermes-default.conf' => [845, null, 'pmssPikkumiesHermesDefaultNginxBlock', 'write Pikkumies Hermes UI default'],
    ] as $name => [$port, $binary, $builder, $operation]) {
        $path = $subdomainConfigDir.'/'.$name;
        if ($subdomainEnabled && $nginxSslBlock !== ''
            && is_file('/etc/seedbox/config/pikkumiesEternals.enabled')
            && ($binary === null || is_file($binary)) && pmssPikkumiesUiPortAvailable($port)) {
            // A failed first attempt also suppresses a second attempt, as before.
            if (!$pagesAttempted && !pmssPikkumiesUiPagesInstall()) {
                pmssCreateNginxConfigSetupWarn('install account UI pages', '/var/www/pikkumies');
            }
            $pagesAttempted = true;
            if (!pmssWriteManagedFile($path, (__NAMESPACE__.'\\'.$builder)($nginxSslBlock), 'root', 'root', 0644)) {
                pmssCreateNginxConfigSetupWarn($operation, $path);
            }
        } else {
            pmssCreateNginxConfigSetupRemoveDefault($path);
        }
    }
    // ADR 0150: Hermes uses the existing panel listener, including on marker hosts.
    pmssCreateNginxConfigSetupRemoveDefault($subdomainConfigDir.'/pmss-hermes-default.conf');
}
