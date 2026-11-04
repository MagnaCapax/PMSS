<?php
/** Customer-side vanilla Hermes state and Configure actions. */

/** Read only bounded account files; never derive a public URL from HTTP_HOST. */
function pmssHermesPanelStatusRead($home): array
{
    $base = $home.'/.hermes';
    $urlFile = $base.'/pmss-dashboard.url';
    $url = is_file($urlFile) && !is_link($urlFile) && filesize($urlFile) <= 512
        ? trim((string) file_get_contents($urlFile)) : '';
    if (!preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,63}/user-'.preg_quote(basename($home), '#').'/hermes/$#D', $url)) $url = '';
    $stateFile = $base.'/pmss-install.status';
    $state = is_file($stateFile) && !is_link($stateFile) && filesize($stateFile) <= 32
        ? trim((string) file_get_contents($stateFile)) : '';
    if ($state === 'ready' && $url === '') $state = 'update-required';
    $reasonFile = $base.'/pmss-install.reason';
    $reason = $state === 'failed' && is_file($reasonFile) && !is_link($reasonFile) && filesize($reasonFile) <= 16
        ? trim((string) file_get_contents($reasonFile)) : '';
    return array('installed' => $state === 'ready' && is_file($home.'/.local/bin/hermes'),
        'state' => in_array($state, array('installing', 'ready', 'failed', 'customer-updated', 'update-required'), true) ? $state : '',
        'reason' => in_array($reason, array('files', 'quota', 'error'), true) ? $reason : '',
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
    $route = $home.'/.lighttpd.conf';
    if (!is_file($route) || is_link($route) || filesize($route) > 1048576
        || strpos((string) @file_get_contents($route), '^/user-'.basename($home).'/hermes/') === false)
        return array('ok' => false, 'status' => 409, 'message' => 'Hermes panel route is unavailable. Please contact support.');
    if (is_file(__DIR__.'/userMediaStackPanel.php')) require_once __DIR__.'/userMediaStackPanel.php';
    $limit = function_exists('pmssMediaStackPanelMemoryLimitBytesRead') ? pmssMediaStackPanelMemoryLimitBytesRead() : null;
    // Same floor as pmssUserHermesMinRamMiB(); the install's dashboard build is what needs it (ADR 0125).
    if ($limit !== null && $limit < 768 * 1048576)
        return array('ok' => false, 'status' => 409, 'message' => 'Hermes needs at least 768 MiB of account memory to install.');
    $base = $home.'/.hermes';
    if (is_link($base) || (!is_dir($base) && !@mkdir($base, 0700)))
        return array('ok' => false, 'status' => 409, 'message' => 'Hermes state is unavailable.');
    if (!pmssConfigureFileWrite($base.'/pmss-install.status', "installing\n"))
        return array('ok' => false, 'status' => 500, 'message' => 'Could not start Hermes setup.');
    @unlink($base.'/pmss-install.reason');
    $command = 'umask 077 && cd '.escapeshellarg($home).' && HOME='.escapeshellarg($home)
        .' nohup /usr/bin/setsid '.escapeshellarg($script).' install </dev/null >>'.escapeshellarg($home.'/.hermes-panel.log').' 2>&1 & echo $!';
    $runner = $ops['run'] ?? 'pmssFrontendShellExec';
    $pid = is_callable($runner) ? trim((string) call_user_func($runner, $command)) : '';
    if (preg_match('/^[1-9][0-9]*$/D', $pid))
        return array('ok' => true, 'status' => 202, 'message' => 'Installing…');
    pmssConfigureFileWrite($base.'/pmss-install.status', "failed\n");
    return array('ok' => false, 'status' => 500, 'message' => 'Could not start Hermes setup.');
}
