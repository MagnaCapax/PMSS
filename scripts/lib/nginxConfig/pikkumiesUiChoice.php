<?php
/** Account UI Choice route rendering; shared fragments come from the facade. */

/** Port C is independent of the Eternals marker and exposes one authenticated UI. */
function pmssHermesUiNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':846';
    $variable = 'pmss_ui_c_'.substr(hash('sha256', $user), 0, 12);
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    // Only the Hermes web choice has a password sign-in; the session exchange always targets that relay.
    $login = pmssPikkumiesHermesLoginLocations('/__pmss-ui-c', $lighttpdPort, 'https://'.$host, 'ALLOW-FROM https://'.$host);
    $relay = pmssPikkumiesUiRelayHeaders();
    $maps = pmssPikkumiesUiOriginMaps($variable, $origin, '$');
    $upgrade = pmssPikkumiesUiUpgradeSettings();
    return <<<NGINX
# Account UI C: Hermes enforces its login behind the private relay.
{$maps}# The Agentboard client sends its API calls without cookies: its token exchange and bearer calls pick it directly.
map "\$request_method:\$request_uri:\$http_authorization:\$http_cookie" \${$variable}_choice {
    default "";
    "~^POST:/api/auth(?:\\?[^:]*)?::" agentboard;
    "~^[A-Z]+:/api/[^:]*:Bearer " agentboard;
    "~(?:^|[:;][[:space:]]*)__Host-pmss_ui_c=(web|agentboard)(?:;|\$)" \$1;
}
map \${$variable}_choice \${$variable}_prefix {
    default /__pmss-ui-c;
    agentboard /__pmss-ui-c-agentboard;
}
map \${$variable}_choice \${$variable}_authorization {
    default "";
    agentboard \$http_authorization;
}
server {
    listen 846 ssl;
    server_name {$serviceHost};
{$ssl}    add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
    add_header X-Frame-Options "ALLOW-FROM https://{$host}" always;
    add_header Cache-Control "no-store" always;
    access_log /var/log/nginx/access.log pmss_ui_noargs;
    proxy_cookie_domain ~.+ "";
    # Hermes answers with its relay prefix in login redirects and cookie paths; the browser only knows /.
    proxy_redirect /__pmss-ui-c/ /;
    proxy_cookie_path /__pmss-ui-c/ /;
    if (\$request_uri ~* "^[^?]*(%2e|%2f|\\.\\.|//)") { return 400; }
    if (\${$variable}_origin_ok = 0) { return 403; }
    if (\${$variable}_api_ok = 0) { return 403; }
    location = /api/cron/fire { return 404; }
    location = /__pmss-select-web {
        add_header Set-Cookie "__Host-pmss_ui_c=web; Path=/; Secure; HttpOnly; SameSite=Lax" always;
        add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
        add_header X-Frame-Options "ALLOW-FROM https://{$host}" always;
        # The browser keeps the launcher's #token fragment across this redirect.
        return 302 /__pmss-login;
    }
    location = /__pmss-select-agentboard {
        add_header Set-Cookie "__Host-pmss_ui_c=agentboard; Path=/; Secure; HttpOnly; SameSite=Lax" always;
        add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
        add_header X-Frame-Options "ALLOW-FROM https://{$host}" always;
        return 302 /;
    }
{$login}    location = /__pmss-auth {
        internal;
        if (\${$variable}_choice = agentboard) { return 204; }
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-c/api/auth/me;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Authorization "";
{$relay}    }
    location ~ ^/(?:login(?:/|\$)|auth/password-login(?:/|\$)|api/auth/providers(?:/|\$)|assets/|ds-assets/|fonts/|fonts-terminal/|favicon\.ico\$) {
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
{$relay}    }
    location /api/ {
        auth_request /__pmss-auth;
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
{$relay}{$upgrade}        error_page 502 503 = @pikkumies_offline;
    }
    location / {
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
{$relay}{$upgrade}        error_page 502 503 = @pikkumies_offline;
    }
    location @pikkumies_offline {
        root /var/www;
        try_files /pikkumies/not-running.html =503;
    }
}
NGINX;
}
