<?php
//echo file_put_contents('../.rtorrentRestart', ' ');
echo exec('touch .rtorrentRestart; chown 777 .rtorrentRestart;');
