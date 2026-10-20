<?php
/** Fixed port-A routing; the host marker and complete TLS lineage gate output. */
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Install root-owned static UI pages outside every customer home. */
function pmssPikkumiesUiPagesInstall(): bool
{
    $target = '/var/www/pikkumies';
    if (!pmssEnsureSafeDir($target, 0755)) return false;
    foreach (array('bar.html', 'not-running.html') as $page) {
        $source = '/etc/seedbox/config/pikkumies-'.$page;
        if (!is_file($source) || is_link($source)) return false;
        $content = @file_get_contents($source);
        if (!is_string($content) || !pmssWriteManagedFile($target.'/'.$page, $content, 'root', 'root', 0644)) return false;
    }
    return true;
}

/** Publish only root-provisioned identity for account-side launchers. */
function pmssPikkumiesUiIdentityPublish(string $user, ?string $serviceId, string $directory = '/var/lib/pmss/public/billing'): bool
{
    if (!pmssValidateUsername($user) || !pmssPathTargetIsSafe($directory, true, false, false)
        || is_link($directory)) return false;
    if (!is_dir($directory) && !@mkdir($directory, 0711, true)) return false;
    if (!is_dir($directory) || is_link($directory) || @fileowner($directory) !== 0) return false;
    @chmod($directory, 0711);
    $path = $directory.'/'.$user;
    if ($serviceId === null) {
        if (!file_exists($path) && !is_link($path)) return true;
        $entry = @lstat($path);
        return is_array($entry) && ($entry['mode'] & 0170000) === 0100000
            && $entry['uid'] === 0 && $entry['nlink'] === 1 && @unlink($path);
    }
    if (pmssUnsignedDecimalIntParse($serviceId) === null || (int) $serviceId < 1) return false;
    return pmssWriteManagedFile($path, $serviceId."\n", 'root', 'root', 0644);
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
        $fields = preg_split('/\s+/', trim($row));
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

/** Port C is independent of the Eternals marker and exposes one authenticated UI. */
function pmssHermesUiNginxBlock(string $user, string $host, string $serviceHost, int $lighttpdPort, string $live = '/etc/letsencrypt/live'): string
{
    $origin = 'https://'.$serviceHost.':846';
    $variable = 'pmss_ui_c_'.substr(hash('sha256', $user), 0, 12);
    $originPattern = preg_quote($origin, '~');
    $ssl = pmssNginxUserSslBlock($user.'.'.$host, '', $live);
    return <<<NGINX
# Account UI C: Hermes enforces its login on the loopback backend.
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
map \$cookie_pmss_ui_c \${$variable}_prefix {
    default /__pmss-ui-c;
    agentboard /__pmss-ui-c-agentboard;
}
map \$cookie_pmss_ui_c \${$variable}_authorization {
    default "";
    agentboard \$http_authorization;
}
server {
    listen 846 ssl;
    server_name {$serviceHost};
{$ssl}    add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
    add_header Cache-Control "no-store" always;
    access_log /var/log/nginx/access.log pmss_ui_noargs;
    if (\${$variable}_origin_ok = 0) { return 403; }
    if (\${$variable}_api_ok = 0) { return 403; }
    location = /api/cron/fire { return 404; }
    location = /__pmss-select-web {
        add_header Set-Cookie "pmss_ui_c=web; Path=/; Secure; HttpOnly; SameSite=Lax" always;
        add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
        return 302 /;
    }
    location = /__pmss-select-agentboard {
        add_header Set-Cookie "pmss_ui_c=agentboard; Path=/; Secure; HttpOnly; SameSite=Lax" always;
        add_header Content-Security-Policy "frame-ancestors https://{$host}" always;
        return 302 /;
    }
    location = /__pmss-auth {
        internal;
        if (\$cookie_pmss_ui_c = agentboard) { return 204; }
        proxy_pass http://127.0.0.1:{$lighttpdPort}/__pmss-ui-c/api/auth/me;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Authorization "";
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Host \$host;
    }
    location ~ ^/(?:login(?:/|\$)|auth/password-login(?:/|\$)|api/auth/providers(?:/|\$)|assets/|ds-assets/|fonts/|fonts-terminal/|favicon\.ico\$) {
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Host \$host;
    }
    location /api/ {
        auth_request /__pmss-auth;
        proxy_pass http://127.0.0.1:{$lighttpdPort}\${$variable}_prefix\$request_uri;
        proxy_set_header Authorization \${$variable}_authorization;
        proxy_set_header X-Forwarded-Proto https;
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
    return "log_format pmss_ui_noargs '\$remote_addr \$request_method \$uri \$status';\n"
        ."server {\n    listen 846 ssl default_server;\n    server_name _;\n"
        .$sslBlock."    return 444;\n}\n";
}
