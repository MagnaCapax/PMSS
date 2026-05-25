<?php
/**
 * Nginx per-user subdomain templates.
 *
 * These templates are rendered by createNginxConfig.php when subdomains are
 * enabled (valid FQDN hostname).
 *
 * @license GPL-3.0-only
 */

function pmssNginxSuspendedLocationBlock(): string
{
    return "    location = /error-suspended.html {\n        root /var/www;\n    }\n    location / {\n        return 302 /error-suspended.html;\n    }";
}

function pmssNginxUserSubdomainTemplates(): array
{
    $suspendedLocations = pmssNginxSuspendedLocationBlock();
    $publicProxyDefaults = <<<'NGINX'
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 100m;
        limit_rate 32768k;
        limit_conn addr 8;
NGINX;
    $privateProxyDefaults = <<<'NGINX'
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 1024m;
        limit_rate 102400k;
        limit_conn addr 16;
        error_page 502 /error-502-##user##.html;
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

    $privateSubdomainTemplate = <<<'NGINX'
# PMSS private subdomain for ##user## (maps to /user-##user##/).
# Private area: do not add public locations to this vhost.
server {
    listen 80;
    server_name ##host##;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name ##host##;

##ssl_block##
    # Legacy Deluge URL path. Keep for compatibility until at least 2028-01-28.
    # Canonical path is /user-##user##/deluge/ (served by per-user lighttpd).
    location = /deluge-##user## {
        return 308 /deluge-##user##/$is_args$args;
    }
    location /deluge-##user##/ {
        # Legacy Deluge URL path: proxy to lighttpd so POST clients don't trip on redirects.
        proxy_pass http://127.0.0.1:##port##/deluge-##user##/;
        proxy_cookie_path /deluge-##user##/ /deluge-##user##/;
##private_proxy_defaults##
    }

    # When apps generate absolute /user-<user>/... URLs, avoid double-prefixing
    # by proxying those paths as-is (without adding another /user-<user>/).
    location ^~ /user-##user##/ {
        proxy_pass http://127.0.0.1:##port##;
        # Deluge map-urlpath can double cookie paths; normalize to canonical base.
        proxy_cookie_path ~^/user-##user##/deluge/user-##user##/deluge/.* /user-##user##/deluge/;
##private_proxy_defaults##
    }

    location / {
        proxy_pass http://127.0.0.1:##port##/user-##user##/;
##private_proxy_defaults##
    }

    location /webdav-##user##/ {
        proxy_pass http://127.0.0.1:##port##/webdav-##user##/;
##webdav_proxy_defaults##
        error_page 502 /error-502-##user##.html;
    }

    location = /error-502-##user##.html {
        root /var/www;
        internal;
        try_files $uri /error-502.html;
    }
}
NGINX;
    $privateSubdomainTemplate = str_replace(
        ['##private_proxy_defaults##', '##webdav_proxy_defaults##'],
        [$privateProxyDefaults, $webdavProxyDefaults],
        $privateSubdomainTemplate
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

    $privateSuspendedTemplate = <<<'NGINX'
# PMSS suspended private subdomain for ##user##.
server {
    listen 80;
    server_name ##host##;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name ##host##;
    root /var/www;

##ssl_block##
##suspended_locations##
}
NGINX;
    $privateSuspendedTemplate = str_replace('##suspended_locations##', $suspendedLocations, $privateSuspendedTemplate);

    return [
        'public' => $publicSubdomainTemplate,
        'private' => $privateSubdomainTemplate,
        'publicSuspended' => $publicSuspendedTemplate,
        'privateSuspended' => $privateSuspendedTemplate,
    ];
}
