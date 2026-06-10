<?php
/**
* PMSS: User Front-End Request rTorrent Restart
* 
* #TODO Probably could just send SIGHUP // kill ...
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
*
**/

echo exec('touch .rtorrentRestart; chmod 777 .rtorrentRestart;');
