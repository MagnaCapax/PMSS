<?php
/** Fixed port-A routing; the host marker and complete TLS lineage gate output. */
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Install root-owned static UI pages outside every customer home. */
function pmssPikkumiesUiPagesInstall(): bool
{
    $target = '/var/www/pikkumies';
    if (!pmssEnsureSafeDir($target, 0755)) return false;
    foreach (array('bar.html', 'not-running.html', 'hermes-login.html') as $page) {
        $source = '/etc/seedbox/config/pikkumies-'.$page;
        if (!is_file($source) || is_link($source)) return false;
        $content = @file_get_contents($source);
        if (!is_string($content) || !pmssWriteManagedFile($target.'/'.$page, $content, 'root', 'root', 0644)) return false;
    }
    return true;
}

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
    $csp = pmssPikkumiesHermesLoginCsp($frameAncestors);
    return <<<NGINX
    location = /__pmss-login {
        alias /var/www/pikkumies/hermes-login.html;
        default_type text/html;
        add_header Content-Security-Policy "{$csp}" always;
        add_header X-Frame-Options "{$frameOptions}" always;
        add_header Cache-Control "no-store" always;
        add_header Referrer-Policy "no-referrer" always;
    }
    location = /__pmss-session {
        limit_except POST { deny all; }
        client_max_body_size 1k;
        proxy_pass http://127.0.0.1:{$lighttpdPort}{$prefix}/__pmss-session;
        proxy_set_header Authorization "";
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
    }

NGINX;
}

/** Keep the managed writer's account-private ownership contract in one place. */
function pmssPikkumiesUiIdentityWrite(string $path, string $serviceId, string $user, ?callable $writer = null): bool
{
    $writer = $writer ?: 'pmssWriteManagedFile';
    return $writer($path, $serviceId."\n", 'root', $user, 0640);
}

function pmssPikkumiesUiIdentityPublish(string $user, ?string $serviceId, string $directory = '/var/lib/pmss/public/billing',
    string $marker = '/etc/seedbox/config/pikkumiesEternals.enabled'): bool
{
    $enabled = is_file($marker) && !is_link($marker);
    if (!pmssValidateUsername($user) || !pmssPathTargetIsSafe($directory, true, false, false)
        || is_link($directory)) return false;
    if (!is_dir($directory) && (!$enabled || !@mkdir($directory, 0711, true))) return !$enabled;
    if (!is_dir($directory) || is_link($directory) || @fileowner($directory) !== 0) return false;
    @chmod($directory, 0711);
    $path = $directory.'/'.$user;
    if (!$enabled || $serviceId === null) {
        if (!file_exists($path) && !is_link($path)) return true;
        $entry = @lstat($path);
        return pmssUserFileStatIsSingleRegular($entry) && $entry['uid'] === 0 && @unlink($path);
    }
    if (pmssUnsignedDecimalIntParse($serviceId) === null || (int) $serviceId < 1) return false;
    return pmssPikkumiesUiIdentityWrite($path, $serviceId, $user);
}

/** Refuse a port owned by a service other than nginx. */
function pmssPikkumiesUiPortAvailable(int $port, ?string $listeners = null): bool
{
    if ($listeners === null) {
        $rows = [];
        exec('ss -H -ltnp 2>/dev/null', $rows, $rc);
        if ($rc !== 0) return false;
        $listeners = implode("\n", $rows);
    }
    foreach (explode("\n", $listeners) as $row) {
        $fields = pmssConfigLineColumns($row, 0, []);
        $local = $fields[3] ?? '';
        if (preg_match('/(?:^|:|\])'.preg_quote((string) $port, '/').'$/', $local) === 1
            && strpos($row, '"nginx"') === false) return false;
    }
    return true;
}

/** The service name must already have an issued per-name certificate. */
function pmssPikkumiesUiCertificateReady(string $user, string $host, string $live = '/etc/letsencrypt/live'): bool
{
    $lineage = $live.'/'.$user.'.'.$host;
    return is_file($lineage.'/fullchain.pem') && is_file($lineage.'/privkey.pem');
}

/** Render one private UI origin and the exact-origin write/upgrade guard. */
function pmssPikkumiesUiNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':844';
    $variable = 'pmss_ui_a_'.substr(hash('sha256', $user), 0, 12);
    $originPattern = preg_quote($origin, '~');
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    return <<<NGINX
# Account UI A: the backend handles bearer authentication on every non-static request.
map "\$request_method:\$http_upgrade:\$http_origin" \${$variable}_origin_ok {
    default 0;
    ~^GET:: 1;
    ~^[A-Z]+:[^:]*:{$originPattern}\$ 1;
}
map "\$request_method:\$uri:\$http_sec_fetch_site" \${$variable}_api_ok {
    default 1;
    ~^GET:/api/.*:(same-origin|none)\$ 1;
    ~^GET:/api/ 0;
}
server {
    listen 844 ssl;
    server_name {$serviceHost};
{$ssl}    add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
    add_header X-Frame-Options "ALLOW-FROM https://{$host}" always;
    add_header Cache-Control "no-store" always;
    if (\${$variable}_origin_ok = 0) { return 403; }
    if (\${$variable}_api_ok = 0) { return 403; }

    location = /bar {
        alias /var/www/pikkumies/bar.html;
        default_type text/html;
    }
    location / {
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-a/;
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_http_version 1.1;
        proxy_read_timeout 3600s;
        limit_conn addr 32;
        proxy_intercept_errors on;
        error_page 502 503 = @pikkumies_offline;
    }
    location @pikkumies_offline {
        root /var/www;
        try_files /pikkumies/not-running.html =503;
    }
}
NGINX;
}

/** Default host consumes stray SNI/Host values before an account vhost can match. */
function pmssPikkumiesUiDefaultNginxBlock(string $sslBlock): string
{
    return "server {\n    listen 844 ssl default_server;\n    server_name _;\n"
        .$sslBlock."    return 444;\n}\n";
}

/** Port B exposes only Hermes' login surface before its own auth request succeeds. */
function pmssPikkumiesHermesNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':845';
    $variable = 'pmss_ui_b_'.substr(hash('sha256', $user), 0, 12);
    $originPattern = preg_quote($origin, '~');
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    $login = pmssPikkumiesHermesLoginLocations('/__pmss-ui-b', $lighttpdPort, "'none'", 'DENY');
    return <<<NGINX
# Account UI B: Hermes login is required at the private relay.
map "\$request_method:\$http_upgrade:\$http_origin" \${$variable}_origin_ok {
    default 0;
    ~^GET::\$ 1;
    ~^[A-Z]+:[^:]*:{$originPattern}\$ 1;
}
map "\$request_method:\$uri:\$http_sec_fetch_site" \${$variable}_api_ok {
    default 1;
    ~^GET:/api/.*:(same-origin|none)\$ 1;
    ~^GET:/api/ 0;
}
server {
    listen 845 ssl;
    server_name {$serviceHost};
{$ssl}    add_header Content-Security-Policy "frame-ancestors 'none'" always;
    add_header X-Frame-Options "DENY" always;
    add_header Cache-Control "no-store" always;
    access_log /var/log/nginx/access.log pmss_ui_b_noargs;
    # Hermes answers with its relay prefix in login redirects and cookie paths; the browser only knows /.
    proxy_redirect /__pmss-ui-b/ /;
    proxy_cookie_path /__pmss-ui-b/ /;
    if (\$request_uri ~* "^[^?]*(%2e|%2f|\\.\\.|//)") { return 400; }
    if (\${$variable}_origin_ok = 0) { return 403; }
    if (\${$variable}_api_ok = 0) { return 403; }
    location = /api/cron/fire { return 404; }
{$login}    location = /__pmss-b-auth {
        internal;
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-b/api/auth/me;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Authorization "";
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
    }
    location ~ ^/(?:login(?:/|\$)|auth/password-login(?:/|\$)|api/auth/providers(?:/|\$)|assets/|ds-assets/|fonts/|fonts-terminal/|favicon\.ico\$) {
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-b\$request_uri;
        proxy_set_header Authorization "";
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
    }
    location / {
        auth_request /__pmss-b-auth;
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-b\$request_uri;
        proxy_set_header Authorization "";
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_http_version 1.1;
        proxy_read_timeout 3600s;
        limit_conn addr 32;
        proxy_intercept_errors on;
        error_page 401 = @pikkumies_b_login;
        error_page 502 503 = @pikkumies_b_offline;
    }
    # Not logged in: pages go to the Hermes login form; API calls and WebSocket upgrades keep their 401.
    location @pikkumies_b_login {
        if (\$uri ~ ^/api/) { return 401; }
        if (\$http_upgrade != "") { return 401; }
        return 302 /login;
    }
    location @pikkumies_b_offline {
        root /var/www;
        try_files /pikkumies/not-running.html =503;
    }
}
NGINX;
}

function pmssPikkumiesHermesDefaultNginxBlock(string $sslBlock): string
{
    return "server {\n    listen 845 ssl default_server;\n    server_name _;\n"
        .$sslBlock."    return 444;\n}\n";
}

/** Port C is independent of the Eternals marker and exposes one authenticated UI. */
function pmssHermesUiNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':846';
    $variable = 'pmss_ui_c_'.substr(hash('sha256', $user), 0, 12);
    $originPattern = preg_quote($origin, '~');
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    // Only the Hermes web choice has a password sign-in; the session exchange always targets that relay.
    $login = pmssPikkumiesHermesLoginLocations('/__pmss-ui-c', $lighttpdPort, 'https://'.$host, 'ALLOW-FROM https://'.$host);
    return <<<NGINX
# Account UI C: Hermes enforces its login behind the private relay.
map "\$request_method:\$http_upgrade:\$http_origin" \${$variable}_origin_ok {
    default 0;
    ~^GET::\$ 1;
    ~^[A-Z]+:[^:]*:{$originPattern}\$ 1;
}
map "\$request_method:\$uri:\$http_sec_fetch_site" \${$variable}_api_ok {
    default 1;
    ~^GET:/api/.*:(same-origin|none)\$ 1;
    ~^GET:/api/ 0;
}
# The Agentboard client sends its API calls without cookies: its token exchange and bearer calls pick it directly.
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
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
    }
    location ~ ^/(?:login(?:/|\$)|auth/password-login(?:/|\$)|api/auth/providers(?:/|\$)|assets/|ds-assets/|fonts/|fonts-terminal/|favicon\.ico\$) {
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
    }
    location /api/ {
        auth_request /__pmss-auth;
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_http_version 1.1;
        proxy_read_timeout 3600s;
        limit_conn addr 32;
        proxy_intercept_errors on;
        error_page 502 503 = @pikkumies_offline;
    }
    location / {
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_http_version 1.1;
        proxy_read_timeout 3600s;
        limit_conn addr 32;
        proxy_intercept_errors on;
        error_page 502 503 = @pikkumies_offline;
    }
    location @pikkumies_offline {
        root /var/www;
        try_files /pikkumies/not-running.html =503;
    }
}
NGINX;
}

function pmssHermesUiDefaultNginxBlock(string $sslBlock): string
{
    return "server {\n    listen 846 ssl default_server;\n    server_name _;\n"
        .$sslBlock."    return 444;\n}\n";
}
