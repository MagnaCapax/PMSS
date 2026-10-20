<?php
/** Fixed port-A routing; the host marker and complete TLS lineage gate output. */
require_once __DIR__.'/../lighttpd/userFileWrite.php';

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
