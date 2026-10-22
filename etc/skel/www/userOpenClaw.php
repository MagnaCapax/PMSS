<?php
/** Account-owned OpenClaw controls; the existing Apps endpoint routes requests here. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/userMediaStackPanel.php';

/**
 * The remote panel heal can leave an older userMediaStackPanel.php beside this file until the next guiv sync.
 * Older helpers silently ignore the launcher and pid arguments and would start the Media Stack installer.
 */
function pmssOpenClawPanelHelpersCurrent(array $minimumParameters = array('pmssMediaStackPanelStartCommandBuild' => 3,
    'pmssMediaStackPanelPidRead' => 2, 'pmssMediaStackPanelPidRunning' => 3)): bool
{
    foreach ($minimumParameters as $function => $minimum) {
        if (!function_exists($function)) return false;
        if ((new ReflectionFunction($function))->getNumberOfParameters() < $minimum) return false;
    }
    return true;
}

/** Load the self-contained customer installer without invoking its CLI entrypoint. */
function pmssOpenClawRuntimeReady(): bool
{
    if (!pmssOpenClawPanelHelpersCurrent()) return false;
    $installer = dirname(__DIR__).'/bin/install-openclaw';
    if (!is_file($installer) || is_link($installer)) return false;
    require_once $installer;
    // The panel heal can precede step2's installer refresh; old ocPaths ignores the explicit home.
    return function_exists('ocPaths') && (new ReflectionFunction('ocPaths'))->getNumberOfParameters() >= 1;
}

/** Keep slow installs visible while using the installer's verified gateway identity. */
function pmssOpenClawStatusRead(string $home, string $fallback): string
{
    if (!pmssOpenClawPanelHelpersCurrent()) return $fallback;
    $pid = pmssMediaStackPanelPidRead($home, '.install-openclaw-web.pid');
    foreach (array(array('install', '--yes'), array('start'), array('stop')) as $args) {
        if (pmssMediaStackPanelPidRunning($home, $pid, array_merge(array('/usr/bin/php', $home.'/bin/install-openclaw'), $args))) return 'installing';
    }
    if (!pmssOpenClawRuntimeReady()) return $fallback;
    $paths = ocPaths($home);
    if (is_file($paths['state'].'/pmss-stopped')) return 'off';
    return ocRunningPid($paths) !== null && ocPortListening(ocReservedPort($paths['port'])) ? 'running' : $fallback;
}

/** Open carries only a short-lived handoff; mutation requests retain the shared POST guard. */
function pmssOpenClawActionHandle(): void
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $action = pmssFrontendActionRequest();
    if ($action !== 'open') pmssFrontendPostActionRequired();
    list($home, $username) = pmssCustomerAccountContext();
    if (!pmssOpenClawRuntimeReady() || !is_file($home.'/.media-stack-port-openclaw')) {
        http_response_code(503);
        echo 'OpenClaw is unavailable; reload after the server update.';
        return;
    }
    $paths = ocPaths($home);
    if ($action === 'open') {
        $env = getenv();
        $env['HOME'] = $home;
        $env['USER'] = $env['LOGNAME'] = $username;
        list($rc, $url) = ocRun(array('/usr/bin/php', $home.'/bin/install-openclaw', 'open-url'), $env);
        $url = trim($url);
        if ($rc !== 0 || strpos($url, ocPublicOrigin().ocBasePath($paths).'/#') !== 0 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            http_response_code(503);
            echo 'OpenClaw could not open. Start it in Configure and check its log.';
            return;
        }
        header('Location: '.$url, true, 302);
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!in_array($action, array('start', 'restart', 'disable'), true)) {
        http_response_code(400);
        echo json_encode(array('message' => 'Unknown OpenClaw action.'));
        return;
    }
    if (pmssOpenClawStatusRead($home, 'off') === 'installing') {
        http_response_code(409);
        echo json_encode(array('message' => 'An OpenClaw action is already running.'));
        return;
    }
    if ($action === 'restart') ocStop($paths);
    $args = $action === 'disable' ? array('stop')
        : (is_file($paths['entry']) ? array('start') : array('install', '--yes'));
    $command = pmssMediaStackPanelStartCommandBuild($home, $username, array(
        'script' => 'bin/install-openclaw', 'interpreter' => '/usr/bin/php', 'args' => $args,
        'pid' => '.install-openclaw-web.pid', 'log' => '.install-openclaw.log'));
    if (!pmssFrontendShellExecAvailable()) {
        http_response_code(503);
        echo json_encode(array('message' => 'Shell execution is unavailable.'));
        return;
    }
    pmssFrontendShellExec($command);
    echo json_encode(array('message' => 'OpenClaw action queued.'));
}
