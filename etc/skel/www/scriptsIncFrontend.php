<?php
/** Frontend helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssFrontendActionRequest')) {
/**
 * Frontend action wrappers require an explicit action request.
 * This keeps the lightweight toggle endpoints on one contract.
 */
function pmssFrontendActionRequest() {
 $action = $_POST['action'] ?? $_GET['action'] ?? '';
 return is_string($action) ? $action : '';
}
}

if (!function_exists('pmssFrontendPostActionRequired')) {
/** Reject cross-site and read-only requests before changing account state. */
function pmssFrontendPostActionRequired() {
 if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
     && strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0) return;
 http_response_code(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 403 : 405);
 exit;
}
}

if (!function_exists('pmssFrontendFunctionAvailable')) {
/** Check whether a customer-side PHP action may call one runtime function. */
function pmssFrontendFunctionAvailable($function) {
 if (!is_string($function) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $function) !== 1) return false;
 $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
 return function_exists($function) && !in_array($function, $disabled, true);
}
}

if (!function_exists('pmssFrontendShellExecAvailable')) {
/**
 * Check whether customer PHP may run shell commands on this host.
 */
function pmssFrontendShellExecAvailable() {
 return pmssFrontendFunctionAvailable('shell_exec');
}
}

if (!function_exists('pmssFrontendShellExec')) {
/**
 * Run a customer-side shell command only when the PHP runtime allows it.
 */
function pmssFrontendShellExec($command) {
 return pmssFrontendShellExecAvailable() ? shell_exec($command) : null;
}
}

if (!function_exists('pmssFrontendToggleAction')) {
/** Preserve both callable handlers and legacy shell-command strings. */
function pmssFrontendToggleAction($enableFile, callable $startHandler, $disableCommand, $restartCommand = null) {
 pmssFrontendPostActionRequired();
 switch (pmssFrontendActionRequest()) {
  case 'start':
    if (!@touch($enableFile) || !is_file($enableFile)) {
      error_log('PMSS frontend toggle: enable marker write failed: '.basename($enableFile));
      http_response_code(500);
      return;
    }
    $startHandler();
    break;

  case 'disable':
    @unlink($enableFile);
    if (is_callable($disableCommand) && !is_string($disableCommand)) $disableCommand();
    else pmssFrontendShellExec($disableCommand);
    break;

  case 'restart':
    $stop = $restartCommand === null ? $disableCommand : $restartCommand;
    if (is_callable($stop) && !is_string($stop)) $stop();
    else pmssFrontendShellExec($stop);
    $startHandler();
    break;
 }
}
}
