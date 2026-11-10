<?php
/** Managed route selection and framing; proxy-rule bytes live in proxyRule.php. @license GPL-3.0-only */
require_once __DIR__.'/proxyRule.php';
require_once dirname(__DIR__).'/toggleAppCatalog.php';

/** Render one selected route, preserving redirects, legacy aliases and catalog policy. */
function pmssLighttpdManagedProxyFragment(string $proxyName, string $user, int $port): string
{
    $base = '/user-'.$user.'/'.$proxyName;
    $pathMap = static function (string $path): array { return [$path.'/' => '/', $path => '']; };
    // Every row has one label, optional redirect suffix and homogeneous proxy rules.
    $routes = [
        'deluge' => ['Deluge', null, [
            ['^'.$base.'($|/)', $pathMap($base), true, false],
            ['^/deluge-'.$user.'($|/)', $pathMap('/deluge-'.$user), true, false],
        ]],
        'rclone' => ['rclone', null, [['^'.$base.'/', [], true, false, true]]],
        'qbittorrent' => ['qBittorrent', '/', [['^'.$base.'/', [$base.'/' => '/'], false, true, false, true, true]]],
        'transmission' => ['Transmission Docker', '/web/', [['^'.$base.'/', [$base.'/' => '/transmission/'], false, false, false, false, true]]],
        'invidious' => ['Invidious', null, [
            ['^/public-'.$user.'/invidious($|/)', $pathMap('/public-'.$user.'/invidious'), false, true],
            ['^/user-'.$user.'/apps/invidious($|/)', $pathMap('/user-'.$user.'/apps/invidious'), false, true],
        ]],
    ];
    if (!isset($routes[$proxyName])) {
        $catalog = pmssToggleAppCatalogRead();
        if (!isset($catalog[$proxyName])) return '';
        // Bearer routes retain the app's token while stripping cached panel Basic (ADR 0111).
        $routes[$proxyName] = [$catalog[$proxyName]['label'], '/', [
            ['^'.$base.'/', [], $catalog[$proxyName]['bearerAuthorization'] ?? false, true, false, false,
                $catalog[$proxyName]['stripAuthorization'] ?? false, $catalog[$proxyName]['forwardedProtoHeader'] ?? ''],
        ]];
    }
    [$label, $redirect, $rules] = $routes[$proxyName];
    $fragment = "# PMSS-managed: {$label} reverse proxy.\n"
        .($proxyName === 'deluge' ? "# Legacy path /deluge-{$user}/ kept for compatibility until at least 2028-01-28.\n" : '')."\n";
    // Redirects stay before their proxy rules and behind the panel's existing gate.
    if ($redirect !== null) {
        $fragment .= '$HTTP["url"] == "'.$base."\" {\n"
            .'  url.redirect = ( "" => "'.$base.$redirect."\" )\n}\n\n";
    }
    foreach ($rules as $rule) {
        $fragment .= pmssLighttpdProxyRuleFragment($rule[0], $port, ...array_slice($rule, 1))."\n\n";
    }
    return $fragment;
}
