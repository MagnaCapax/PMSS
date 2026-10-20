<?php
/** Internal unprefixed upstream mount for the account's simplified UI. */

function pmssLighttpdPikkumiesUiBlock(string $user): string
{
    if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) !== 1) return '';
    $socket = '/home/'.$user.'/.mcxPikkumies/pikkumies/local/ui/ui.sock';
    return <<<LIGHTTPD

# This internal prefix is removed before the account UI sees the request.
\$HTTP["url"] =~ "^/__pmss-ui-a/" {
    proxy.server = ( "" => (( "socket" => "{$socket}" )) )
    proxy.header = ( "upgrade" => "enable", "map-urlpath" => ( "/__pmss-ui-a/" => "/" ) )
    server.max-read-idle = 3600
    server.max-write-idle = 3600
}
LIGHTTPD;
}
