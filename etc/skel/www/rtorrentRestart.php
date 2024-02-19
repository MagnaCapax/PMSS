<?php
/**
* PMSS: User Front-End Request rTorrent Restart
* 
* #TODO Probably could just send SIGHUP // kill ...
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
**/

echo exec('touch .rtorrentRestart; chown 777 .rtorrentRestart;');
