<?php
require_once __DIR__.'/scriptsInc.php';
$action = pmssFrontendActionRequest();
if ($action !== 'confirm-backup') die();
// Self-service backup of the user's OWN config + torrent-session state (NOT media in ~/data).
// Runs as the user via their own php-cgi -> inherently scoped to the user's own files.
echo pmssFrontendShellExec('tar czf ~/backup-config.tar.gz -C ~ --ignore-failed-read .rtorrent.rc .rtorrent.rc.custom .rtorrentExecute.php session watch .config/qBittorrent .config/deluge 2>&1');
