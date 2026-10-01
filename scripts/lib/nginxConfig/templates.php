<?php
/**
 * Nginx per-user subdomain templates.
 *
 * These templates are rendered by createNginxConfig.php when subdomains are
 * enabled (valid FQDN hostname).
 *
 * @license GPL-3.0-only
 */

function pmssNginxUserSubdomainTemplates(): array
{
    $suspendedLocations = "    location = /error-suspended.html {\n        root /var/www;\n    }\n    location / {\n        return 302 /error-suspended.html;\n    }";
    $publicProxyDefaults = <<<'NGINX'
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 100m;
        limit_rate 32768k;
        limit_conn addr 8;
NGINX;
    $webdavProxyDefaults = <<<'NGINX'
        include /etc/nginx/webdav_proxy_params;
        proxy_http_version 1.1;

        # WebDAV: allow large uploads.
        client_max_body_size 0;

        limit_rate_after 100m;
        limit_rate 102400k;
        limit_conn addr 16;
NGINX;
    $publicSubdomainTemplate = <<<'NGINX'
# PMSS public subdomain for ##user## (maps to /public-##user##/).
server {
    listen 80;
    server_name ##host##;

    # ACME HTTP-01 challenge for opt-in per-name certificates (ADR 0039).
    # Served from a fixed root-owned webroot so certbot runs --webroot and never
    # parses-and-patches this generated config (ADR 0036). Takes precedence over
    # the proxy below via ^~.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/acme-challenge;
        default_type "text/plain";
        try_files $uri =404;
    }

    location / {
        proxy_pass http://127.0.0.1:##port##/public-##user##/;
##public_proxy_defaults##
    }

    location /webdav-##user##/ {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name ##host##;

##ssl_block##
    location / {
        proxy_pass http://127.0.0.1:##port##/public-##user##/;
##public_proxy_defaults##
    }

    location /webdav-##user##/ {
        proxy_pass http://127.0.0.1:##port##/webdav-##user##/;
##webdav_proxy_defaults##
    }
}
NGINX;
    $publicSubdomainTemplate = str_replace(
        ['##public_proxy_defaults##', '##webdav_proxy_defaults##'],
        [$publicProxyDefaults, $webdavProxyDefaults],
        $publicSubdomainTemplate
    );

    $publicSuspendedTemplate = <<<'NGINX'
# PMSS suspended subdomain for ##user##.
server {
    listen 80;
    server_name ##host##;
    root /var/www;

##suspended_locations##
}

server {
    listen 443 ssl;
    server_name ##host##;
    root /var/www;

##ssl_block##
##suspended_locations##
}
NGINX;
    $publicSuspendedTemplate = str_replace('##suspended_locations##', $suspendedLocations, $publicSuspendedTemplate);

    return [
        'public' => $publicSubdomainTemplate,
        'publicSuspended' => $publicSuspendedTemplate,
    ];
}
