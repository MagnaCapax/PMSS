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
 * Check whether customer PHP may run shell commands on this host.
 */
function pmssFrontendShellExecAvailable() {
 $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
 return function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
}

/**
 * Run a customer-side shell command only when the PHP runtime allows it.
 */
function pmssFrontendShellExec($command) {
 return pmssFrontendShellExecAvailable() ? shell_exec($command) : null;
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
    pmssFrontendShellExec($disableCommand);
    break;

  case 'restart':
    pmssFrontendShellExec($restartCommand === null ? $disableCommand : $restartCommand);
    $startHandler();
    break;
 }
}
