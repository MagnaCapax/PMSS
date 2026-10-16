<?php
/** 
 * PMSS User Home Scripts Inc.
 * 
 * Copyright 2010-2024 Magna Capax Finland Oy
 */
function writeLog($msg) {
 $msg = date('Y-m-d H:i:s') . '|| ' . $msg . "\n";
 file_put_contents('rTorrentLog', $msg, FILE_APPEND);

}

/** Load delivered customer helpers in dependency order; absent parts only remove their helpers. */
foreach (array(
 'Format', 'Files', 'Frontend', 'Bonus', 'Cgroup', 'Backup',
 'RestoreTar', 'Restore', 'AppProcess', 'AppsHtml', 'AppsStatus', 'ActionJs',
) as $part) {
 $p = __DIR__.'/scriptsInc'.$part.'.php';
 if (is_file($p) && !is_link($p)) require_once $p;
}
unset($part, $p);
