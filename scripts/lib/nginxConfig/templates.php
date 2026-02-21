<?php
/**
 * Nginx per-user subdomain templates.
 *
 * These templates are rendered by createNginxConfig.php when subdomains are
 * enabled (valid FQDN hostname). Kept in a dedicated module so the entrypoint
 * stays small while preserving stable template content for tests.
 *
 * @license GPL-3.0-only
 */

function pmssNginxUserSubdomainTemplates(): array
{
    $publicSubdomainTemplate = <<<'NGINX'
# PMSS public subdomain for ##user## (maps to /public-##user##/).
server {
    listen 80;
    server_name ##host##;

    location / {
        proxy_pass http://127.0.0.1:##port##/public-##user##/;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 100m;
        limit_rate 32768k;
        limit_conn addr 8;
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
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 100m;
        limit_rate 32768k;
        limit_conn addr 8;
    }

    location /webdav-##user##/ {
        proxy_pass http://127.0.0.1:##port##/webdav-##user##/;
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;

        # WebDAV: allow large uploads.
        client_max_body_size 0;

        limit_rate_after 100m;
        limit_rate 102400k;
        limit_conn addr 16;
    }
}
NGINX;

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
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 1024m;
        limit_rate 102400k;
        limit_conn addr 16;
        error_page 502 /error-502.html;
    }

    # When apps generate absolute /user-<user>/... URLs, avoid double-prefixing
    # by proxying those paths as-is (without adding another /user-<user>/).
    location ^~ /user-##user##/ {
        proxy_pass http://127.0.0.1:##port##;
        # Deluge map-urlpath can double cookie paths; normalize to canonical base.
        proxy_cookie_path ~^/user-##user##/deluge/user-##user##/deluge/.* /user-##user##/deluge/;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 1024m;
        limit_rate 102400k;
        limit_conn addr 16;
        error_page 502 /error-502.html;
    }

    location / {
        proxy_pass http://127.0.0.1:##port##/user-##user##/;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 1024m;
        limit_rate 102400k;
        limit_conn addr 16;
        error_page 502 /error-502.html;
    }

    location /webdav-##user##/ {
        proxy_pass http://127.0.0.1:##port##/webdav-##user##/;
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;

        # WebDAV: allow large uploads.
        client_max_body_size 0;

        limit_rate_after 100m;
        limit_rate 102400k;
        limit_conn addr 16;
        error_page 502 /error-502.html;
    }

    location = /error-502.html {
        root /var/www;
        internal;
    }
}
NGINX;

    $publicSuspendedTemplate = <<<'NGINX'
# PMSS suspended subdomain for ##user##.
server {
    listen 80;
    server_name ##host##;
    root /var/www;

    location = /error-suspended.html {
        root /var/www;
    }
    location / {
        return 302 /error-suspended.html;
    }
}

server {
    listen 443 ssl;
    server_name ##host##;
    root /var/www;

##ssl_block##
    location = /error-suspended.html {
        root /var/www;
    }
    location / {
        return 302 /error-suspended.html;
    }
}
NGINX;

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
    location = /error-suspended.html {
        root /var/www;
    }
    location / {
        return 302 /error-suspended.html;
    }
}
NGINX;

    return [
        'public' => $publicSubdomainTemplate,
        'private' => $privateSubdomainTemplate,
        'publicSuspended' => $publicSuspendedTemplate,
        'privateSuspended' => $privateSuspendedTemplate,
    ];
}
