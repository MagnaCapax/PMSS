<?php
/** Pure lighttpd proxy-rule rendering. @license GPL-3.0-only */

/** Render one proxy conditional with the caller's existing authentication and header policy. */
function pmssLighttpdProxyRuleFragment(
    string $pattern,
    int $port,
    array $pathMap = [],
    bool $disableAuth = false,
    bool $forwardForwardedHeaders = false,
    bool $injectZeroContentLengthOnEmptyPost = false,
    bool $preserveRequestHostHeader = false,
    bool $stripAuthorization = false,
    string $forwardedProtoHeader = ''
): string {
    $fragment = '$HTTP["url"] =~ "'.$pattern."\" {\n";
    if ($disableAuth) {
        $fragment .= "  auth.require = ()\n";
    }
    if ($stripAuthorization && $disableAuth) {
        // Ungated Bearer route (ADR 0111, 2026-11-08): the app's own token passes; only the
        // browser's cached panel Basic credential is removed before the proxy.
        $fragment .= '  $REQUEST_HEADER["Authorization"] =~ "^Basic " {'."\n"
            .'    setenv.set-request-header = ( "Authorization" => "" )'."\n  }\n";
    } elseif ($stripAuthorization) {
        // Keep panel Basic credentials out of the app's independent login.
        $fragment .= '  setenv.set-request-header = ( "Authorization" => "" )'."\n";
    }
    if ($forwardedProtoHeader !== '') {
        // Older mod_proxy replaces X-Forwarded-Proto with the pre-extforward scheme.
        // Overwrite client values using only the scheme accepted by mod_extforward.
        // A nested setenv map replaces its parent's map; retain credential stripping.
        foreach (['http', 'https'] as $scheme) {
            $fragment .= '  $HTTP["scheme"] == "'.$scheme."\" {\n"
                .'    setenv.set-request-header = ( "'.$forwardedProtoHeader.'" => "'.$scheme.'"'
                .($stripAuthorization ? ', "Authorization" => ""' : '').' )'."\n  }\n";
        }
    }
    if ($injectZeroContentLengthOnEmptyPost) {
        $fragment .= '  $HTTP["request-method"] == "POST" {'."\n"
            .'    $REQUEST_HEADER["Content-Length"] == "" {'."\n"
            .'      setenv.set-request-header = ( "Content-Length" => "0" )'."\n"
            .'    }'."\n"
            .'  }'."\n";
    }

    $fragment .= "  proxy.server = ( \"\" => ( (\n    \"host\" => \"127.0.0.1\",\n    \"port\" => {$port}\n  ) ) ),\n";
    if ($preserveRequestHostHeader) {
        // qBittorrent validates Host/Origin against the browser-visible origin.
        $fragment .= "  proxy.replace-http-host = \"disable\"\n";
    }
    if ($forwardForwardedHeaders) {
        $fragment .= "  proxy.forwarded = ( \"for\" => 1,\n                      \"host\" => 1,\n                      \"by\" => 1\n  )";
        $fragment .= ",\n";
    }
    $fragment .= "  proxy.header = (\n      \"upgrade\" => \"enable\"";
    if ($pathMap !== []) {
        $mappings = [];
        foreach ($pathMap as $source => $target) {
            $mappings[] = '         "'.$source.'"'.(substr($source, -1) === '/' ? '  ' : ' ').'=> "'.$target.'"';
        }
        $fragment .= ",\n      \"map-urlpath\" => (\n".implode(",\n", $mappings)."\n       )";
    }
    $fragment .= "\n  )\n";

    return $fragment.'}';
}
