#!/usr/bin/php
<?php
/**
 * Ensure root (user-0.slice) is not limited by memory/tasks policies.
 * Runs safely in cron; fixes limits if detected.
 */

require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/runtime.php';

function v($k){ $o=@shell_exec('systemctl show user-0.slice -p '.$k.' 2>/dev/null'); if($o===null)return ''; $o=trim($o); $pos=strpos($o,'='); return $pos!==false?substr($o,$pos+1):$o; }

$memHigh = v('MemoryHigh');
$memMax  = v('MemoryMax');
$tasks   = v('TasksMax');

$fixes=[];
if ($memHigh !== '' && strtolower($memHigh) !== 'infinity') { $fixes['MemoryHigh']='infinity'; }
if ($memMax  !== '' && strtolower($memMax)  !== 'infinity') { $fixes['MemoryMax']='infinity'; }
if ($tasks   !== '' && strtolower($tasks)   !== 'infinity') { $fixes['TasksMax']='infinity'; }

if ($fixes) {
    // In test mode, allow running without root to exercise logging paths
    if (!defined('PMSS_TEST_MODE') && getenv('PMSS_TEST_MODE') !== '1') {
        requireRoot();
    }
    $pairs=[]; foreach($fixes as $k=>$v){ $pairs[]=$k.'='.$v; }
    runStep('Unlimiting root user slice', 'systemctl set-property user-0.slice '.implode(' ', $pairs));
} else {
    logmsg('[OK] Root slice already unlimited');
}
