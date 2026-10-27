<?php
/** Account UI Hermes route rendering; shared fragments come from the facade. */

/** Policy for the Hermes sign-in page: only its own inline script and style (hashes are pinned by a test). */
function pmssPikkumiesHermesLoginCsp(string $frameAncestors): string
{
    return "default-src 'none'; script-src 'sha256-xXVfKKpYclN4U3VubqbW/KtXwxmEgEpYynRuDvxAVds='; "
        ."style-src 'sha256-rFLJF6Eeitnq7ESukREg5se00WyD23kPbRye5XqTRHA='; connect-src 'self'; "
        ."base-uri 'none'; form-action 'none'; frame-ancestors ".$frameAncestors;
}

/**
 * The sign-in page and its single-use token exchange. The token rides in the page URL fragment, so no server
 * logs it; the page posts it to the account relay, which spends it and answers with Hermes' own session cookies.
 */
function pmssPikkumiesHermesLoginLocations(string $prefix, int $lighttpdPort, string $frameAncestors, string $frameOptions): string
{
    $frame = pmssPikkumiesUiFrameHeaders(pmssPikkumiesHermesLoginCsp($frameAncestors), $frameOptions, '        ');
    $proxy = pmssPikkumiesUiProxyBody($lighttpdPort, $prefix.'/__pmss-session', '""');
    return <<<NGINX
    location = /__pmss-login {
        alias /var/www/pikkumies/hermes-login.html;
        default_type text/html;
{$frame}        add_header Cache-Control "no-store" always;
        add_header Referrer-Policy "no-referrer" always;
    }
    location = /__pmss-session {
        limit_except POST { deny all; }
        client_max_body_size 1k;
{$proxy}    }

NGINX;
}

/** Port B exposes only Hermes' login surface before its own auth request succeeds. */
function pmssPikkumiesHermesNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':845';
    $variable = 'pmss_ui_b_'.substr(hash('sha256', $user), 0, 12);
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    $login = pmssPikkumiesHermesLoginLocations('/__pmss-ui-b', $lighttpdPort, "'none'", 'DENY');
    $relay = pmssPikkumiesUiRelayHeaders();
    $maps = pmssPikkumiesUiOriginMaps($variable, $origin, '$');
    $upgrade = pmssPikkumiesUiUpgradeSettings();
    $checks = pmssPikkumiesUiOriginChecks($variable);
    $proxy = pmssPikkumiesUiProxyBody($lighttpdPort, '/__pmss-ui-b$request_uri', '""');
    $offline = pmssPikkumiesUiOfflineLocation('pikkumies_b_offline');
    return "# Account UI B: Hermes login is required at the private relay.\n".$maps
        .pmssPikkumiesUiServer("    listen 845 ssl;\n", $serviceHost, $ssl, "'none'", 'DENY', <<<NGINX
    access_log /var/log/nginx/access.log pmss_ui_b_noargs;
    # Hermes answers with its relay prefix in login redirects and cookie paths; the browser only knows /.
    proxy_redirect /__pmss-ui-b/ /;
    proxy_cookie_path /__pmss-ui-b/ /;
    if (\$request_uri ~* "^[^?]*(%2e|%2f|\\.\\.|//)") { return 400; }
{$checks}    location = /api/cron/fire { return 404; }
{$login}    location = /__pmss-b-auth {
        internal;
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-b/api/auth/me;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Authorization "";
{$relay}    }
    location ~ ^/(?:login(?:/|\$)|auth/password-login(?:/|\$)|api/auth/providers(?:/|\$)|assets/|ds-assets/|fonts/|fonts-terminal/|favicon\.ico\$) {
{$proxy}    }
    location / {
        auth_request /__pmss-b-auth;
{$proxy}{$upgrade}        error_page 401 = @pikkumies_b_login;
        error_page 502 503 = @pikkumies_b_offline;
    }
    # Not logged in: pages go to the Hermes login form; API calls and WebSocket upgrades keep their 401.
    location @pikkumies_b_login {
        if (\$uri ~ ^/api/) { return 401; }
        if (\$http_upgrade != "") { return 401; }
        return 302 /login;
    }
{$offline}
NGINX
    );
}
