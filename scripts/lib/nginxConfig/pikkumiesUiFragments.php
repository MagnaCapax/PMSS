<?php
/** Shared string fragments for account UI routes; callers retain authorization policy. */

/** Preserve the distinct port-A GET prefix and the anchored Hermes GET rule. */
function pmssPikkumiesUiOriginMaps(string $variable, string $origin, string $getEnd): string
{
    $originPattern = preg_quote($origin, '~');
    return <<<NGINX
map "\$request_method:\$http_upgrade:\$http_origin" \${$variable}_origin_ok {
    default 0;
    ~^GET::{$getEnd} 1;
    ~^[A-Z]+:[^:]*:{$originPattern}\$ 1;
}
map "\$request_method:\$uri:\$http_sec_fetch_site" \${$variable}_api_ok {
    default 1;
    ~^GET:/api/.*:(same-origin|none)\$ 1;
    ~^GET:/api/ 0;
}

NGINX;
}

/** The trusted nginx-to-lighttpd hop must be emitted together in every relay location. */
function pmssPikkumiesUiRelayHeaders(): string
{
    return <<<NGINX
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;
        proxy_bind 127.0.0.2;
        proxy_set_header Forwarded "for=\\"\$remote_addr\\";proto=https";
        proxy_set_header Host \$host;

NGINX;
}

/** Common WebSocket and long-running request settings, with the original directive order. */
function pmssPikkumiesUiUpgradeSettings(): string
{
    return <<<NGINX
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_http_version 1.1;
        proxy_read_timeout 3600s;
        limit_conn addr 32;
        proxy_intercept_errors on;

NGINX;
}

/** Reject unmatched names; the public wrappers retain explicit, statically checked listen lines. */
function pmssPikkumiesUiDefaultServer(string $listenLine, string $sslBlock): string
{
    return "server {\n".$listenLine."    server_name _;\n"
        .$sslBlock."    return 444;\n}\n";
}
