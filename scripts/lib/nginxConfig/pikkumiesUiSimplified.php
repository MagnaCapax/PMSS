<?php
/** Simplified account UI route rendering; shared fragments come from the facade. */

/** Render one private UI origin and the exact-origin write/upgrade guard. */
function pmssPikkumiesUiNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':844';
    $variable = 'pmss_ui_a_'.substr(hash('sha256', $user), 0, 12);
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    $maps = pmssPikkumiesUiOriginMaps($variable, $origin, '');
    $upgrade = pmssPikkumiesUiUpgradeSettings();
    $checks = pmssPikkumiesUiOriginChecks($variable);
    $offline = pmssPikkumiesUiOfflineLocation('pikkumies_offline');
    return "# Account UI A: the backend handles bearer authentication on every non-static request.\n".$maps
        .pmssPikkumiesUiServer("    listen 844 ssl;\n", $serviceHost, $ssl, 'https://'.$host, 'ALLOW-FROM https://'.$host, <<<NGINX
{$checks}
    location = /bar {
        alias /var/www/pikkumies/bar.html;
        default_type text/html;
    }
    location / {
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-a/;
        proxy_set_header Host \$host;
{$upgrade}        error_page 502 503 = @pikkumies_offline;
    }
{$offline}
NGINX
    );
}
