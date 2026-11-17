<?php
/** Panel-authenticated mount for the account's simplified UI. */

function pmssLighttpdPikkumiesUiBlock(string $user): string
{
    if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) !== 1) return '';
    $socket = '/home/'.$user.'/.mcxPikkumies/pikkumies/local/ui/ui.sock';
    return <<<LIGHTTPD

# The existing panel zone authenticates before Authorization is cleared.
\$HTTP["url"] =~ "^/user-{$user}/pikkumies/" {
    setenv.set-request-header = ( "Authorization" => "" )
    proxy.server = ( "" => (( "socket" => "{$socket}" )) )
    proxy.header = ( "upgrade" => "enable", "map-urlpath" => ( "/user-{$user}/pikkumies/" => "/" ) )
    server.max-read-idle = 3600
    server.max-write-idle = 3600
}
LIGHTTPD;
}

/** The panel reaches only the account's separate Pikkumies Hermes relay. */
function pmssLighttpdPikkumiesHermesBlock(string $user): string
{
    if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) !== 1) return '';
    $socket = '/home/'.$user.'/.mcxPikkumies/pikkumies/local/ui/hermes/ui.sock';
    return <<<LIGHTTPD

\$HTTP["url"] =~ "^/user-{$user}/pikkumies-hermes/" {
    setenv.set-request-header = ( "Authorization" => "" )
    setenv.set-request-header += ( "X-Forwarded-Prefix" => "/user-{$user}/pikkumies-hermes" )
    proxy.server = ( "" => (( "socket" => "{$socket}" )) )
    proxy.header = ( "upgrade" => "enable", "map-urlpath" => ( "/user-{$user}/pikkumies-hermes/" => "/" ) )
    server.max-read-idle = 3600
    server.max-write-idle = 3600
}
LIGHTTPD;
}

/** Panel auth runs before mod_proxy; the upstream only receives its separate Hermes session. */
function pmssLighttpdHermesUiBlock(string $user, int $port, string $mode = 'vanilla-hermes-web'): string
{
    if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) !== 1 || $port < 1024 || $port > 65535) return '';
    $upstream = $mode === 'vanilla-agentboard'
        ? '"socket" => "/home/'.$user.'/.config/pmss/ui/agentboard.sock"'
        : '"socket" => "/home/'.$user.'/.hermes/pmss-dashboard.sock"';
    $prefix = $mode === 'vanilla-agentboard' ? '/__pmss-ui-c-agentboard/' : '/user-'.$user.'/hermes/';
    $authHeader = $mode === 'vanilla-agentboard' ? '' : "    setenv.set-request-header = ( \"Authorization\" => \"\" )\n";
    return <<<LIGHTTPD

# Hermes stays inside the existing authenticated panel zone (ADR 0150).
\$HTTP["url"] =~ "^{$prefix}" {
{$authHeader}    proxy.server = ( "" => (( {$upstream} )) )
    setenv.set-request-header += ( "X-Forwarded-Prefix" => "/user-{$user}/hermes" )
    proxy.header = ( "upgrade" => "enable", "map-urlpath" => ( "{$prefix}" => "/" ) )
    server.max-read-idle = 3600
    server.max-write-idle = 3600
}
LIGHTTPD;
}
