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

/**
 * Frontend action wrappers require an explicit action request.
 * This keeps the lightweight toggle endpoints on one contract.
 */
function pmssFrontendActionRequest() {
 if (!isset($_REQUEST['action'])) die();

 return (string) $_REQUEST['action'];
}

/**
 * Shared start/disable/restart toggle flow for lightweight app frontends.
 * The callers provide the enable marker path plus app-specific commands.
 */
function pmssFrontendToggleAction($enableFile, callable $startHandler, $disableCommand, $restartCommand = null) {
 switch (pmssFrontendActionRequest()) {
  case 'start':
    touch($enableFile);
    $startHandler();
    break;

  case 'disable':
    unlink($enableFile);
    shell_exec($disableCommand);
    break;

  case 'restart':
    shell_exec($restartCommand === null ? $disableCommand : $restartCommand);
    $startHandler();
    break;
 }
}
