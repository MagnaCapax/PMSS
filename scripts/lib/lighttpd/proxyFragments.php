<?php
/**
 * Reverse proxy fragments for per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssDelugeLighttpdProxyFragment(string $user, int $webPort): string
{
    $deprecationDate = '2028-01-28';

    return <<<LIGHTTPD
# PMSS-managed: Deluge reverse proxy.
# Legacy path /deluge-{$user}/ kept for compatibility until at least {$deprecationDate}.

\$HTTP["url"] =~ "^/user-{$user}/deluge($|/)" {
  auth.require = ()
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$webPort}
  ) ) ),
  proxy.header = (
      "map-urlpath" => (
         "/user-{$user}/deluge/"  => "/",
         "/user-{$user}/deluge" => ""
       )
  )
}

\$HTTP["url"] =~ "^/deluge-{$user}($|/)" {
  auth.require = ()
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$webPort}
  ) ) ),
  proxy.header = (
      "map-urlpath" => (
         "/deluge-{$user}/"  => "/user-{$user}/deluge/",
         "/deluge-{$user}" => "/user-{$user}/deluge"
       )
  )
}

LIGHTTPD;
}

function pmssRcloneLighttpdProxyFragment(string $user, int $port): string
{
    return <<<LIGHTTPD
# PMSS-managed: rclone reverse proxy.

\$HTTP["url"] =~ "^/user-{$user}/rclone/" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$port}
  ) ) )
}

LIGHTTPD;
}

function pmssQbittorrentLighttpdProxyFragment(string $user, int $port): string
{
    return <<<LIGHTTPD
# PMSS-managed: qBittorrent reverse proxy.

\$HTTP["url"] =~ "^/user-{$user}/qbittorrent/" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$port}
  ) ) ),
  proxy.forwarded = ( "for" => 1,
                      "host" => 1,
                      "by" => 1
  ),
  proxy.header = (
      "map-urlpath" => (
         "/user-{$user}/qbittorrent/"  => "/",
         "/user-{$user}/qbittorrent" => ""
       )
  )
}

LIGHTTPD;
}
