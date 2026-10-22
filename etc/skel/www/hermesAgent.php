<?php
/** Customer-side vanilla Hermes state and Configure actions. */

/** Read only bounded account files; never derive a public URL from HTTP_HOST. */
function pmssHermesPanelStatusRead($home): array
{
    $base = $home.'/.hermes';
    $urlFile = $base.'/pmss-dashboard.url';
    $url = is_file($urlFile) && !is_link($urlFile) && filesize($urlFile) <= 128
        ? trim((string) file_get_contents($urlFile)) : '';
    if (!preg_match('#^https://[0-9a-f]{16}\.mcx\.fi:846/$#D', $url)) $url = '';
    $stateFile = $base.'/pmss-install.status';
    $state = is_file($stateFile) && !is_link($stateFile) && filesize($stateFile) <= 32
        ? trim((string) file_get_contents($stateFile)) : '';
    return array('installed' => $state === 'ready' && is_file($home.'/.local/bin/hermes'),
        'state' => in_array($state, array('installing', 'ready', 'failed', 'customer-updated'), true) ? $state : '',
        'url' => $url);
}

/** Configure's POST and password gate have already run before this helper. */
function pmssHermesPanelAction($home, $action, $request, $ops = array()): array
{
    if (!in_array($action, array('install', 'show-login'), true))
        return array('ok' => false, 'status' => 400, 'message' => 'Unknown Hermes action.');
    if ($action === 'show-login') {
        $file = $home.'/.hermes/pmss-dashboard.env';
        clearstatcache(true, $file);
        if (!is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0 || filesize($file) > 2048)
            return array('ok' => false, 'status' => 409, 'message' => 'Hermes login is unavailable.');
        $raw = file_get_contents($file);
        if (!preg_match('/^HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=([a-f0-9]{64})/D', $raw, $m))
            return array('ok' => false, 'status' => 409, 'message' => 'Hermes login is unavailable.');
        return array('ok' => true, 'status' => 200, 'message' => $m[1]);
    }
    $script = $home.'/bin/install-hermes';
    if (!is_file($script) || is_link($script) || !is_executable($script))
        return array('ok' => false, 'status' => 409, 'message' => 'Hermes installer is unavailable.');
    if (is_file(__DIR__.'/userMediaStackPanel.php')) require_once __DIR__.'/userMediaStackPanel.php';
    $limit = function_exists('pmssMediaStackPanelMemoryLimitBytesRead') ? pmssMediaStackPanelMemoryLimitBytesRead() : null;
    if ($limit !== null && $limit < 1000 * 1048576)
        return array('ok' => false, 'status' => 409, 'message' => 'Hermes needs at least 1000 MiB of account memory.');
    $base = $home.'/.hermes';
    if (is_link($base) || (!is_dir($base) && !@mkdir($base, 0700)))
        return array('ok' => false, 'status' => 409, 'message' => 'Hermes state is unavailable.');
    if (!pmssConfigureFileWrite($base.'/pmss-install.status', "installing\n"))
        return array('ok' => false, 'status' => 500, 'message' => 'Could not start Hermes setup.');
    $command = 'umask 077 && cd '.escapeshellarg($home).' && HOME='.escapeshellarg($home)
        .' nohup '.escapeshellarg($script).' install </dev/null >>'.escapeshellarg($home.'/.hermes-panel.log').' 2>&1 & echo $!';
    $runner = $ops['run'] ?? 'pmssFrontendShellExec';
    $pid = is_callable($runner) ? trim((string) call_user_func($runner, $command)) : '';
    if (preg_match('/^[1-9][0-9]*$/D', $pid))
        return array('ok' => true, 'status' => 202, 'message' => 'Installing…');
    pmssConfigureFileWrite($base.'/pmss-install.status', "failed\n");
    return array('ok' => false, 'status' => 500, 'message' => 'Could not start Hermes setup.');
}
