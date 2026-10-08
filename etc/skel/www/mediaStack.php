<?php
/**
 * PMSS: Welcome-page media stack launcher endpoint.
 *
 * Starts the existing per-user install-media-stack.sh script and reports a
 * bounded status payload for the welcome page AJAX widget.
 *
 * Copyright (C) 2010-2026 Magna Capax Finland Oy
 */

// Customer-side helper relocated to etc/skel/www/userMediaStackPanel.php
// per ADR 0016 (commit 78a21364). /scripts/ is operator-only, unreachable
// from customer PHP.
require_once __DIR__.'/userMediaStackPanel.php';

$home = dirname(__DIR__);
$username = basename(rtrim($home, '/'));
$hostname = function_exists('gethostname') ? (string) gethostname() : '';
$hostname = $hostname !== '' ? $hostname : (string) php_uname('n');
$action = $_GET['action'] ?? 'status';
$action = is_string($action) ? $action : 'status';

if (isset($_POST['action']) && is_string($_POST['action']) && strpos($_POST['action'], 'confirm-secure-') === 0) {
    pmssMediaStackPanelSecureHandle($home, $username, $hostname);
} elseif ($action === 'start') {
    pmssMediaStackPanelStartHandle($home, $username, $hostname);
} elseif ($action === 'start-stopped') {
    pmssMediaStackPanelRecoveryHandle($home, $username, $hostname);
} elseif (in_array($action, array('app-start', 'app-stop', 'app-restart'), true)) {
    pmssMediaStackPanelAppActionHandle($home, $username, $hostname, $action);
}

pmssMediaStackPanelJsonRespond(pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname));

/**
 * Return the combined JSON payload used by the welcome page widget.
 *
 * @return array<string,mixed>
 */
function pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname)
{
    $status = pmssMediaStackPanelStatusRead($home, $username, $hostname);
    return array(
        'message' => $status['message'],
        'html' => pmssMediaStackPanelHtmlBuild($status),
        'canStart' => !empty($status['canStart']),
        'canRestart' => !empty($status['canRestart']),
        'poll' => !empty($status['poll']),
        'state' => $status['state'],
    );
}

/** Change exactly one installed customer's tmux app and its opt-out marker. */
function pmssMediaStackPanelAppActionHandle($home, $username, $hostname, $action)
{
    $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
    if (!pmssMediaStackPanelRecoveryRequestAllowed($_SERVER)) {
        $payload['message'] = 'App controls require a panel POST request.';
        pmssMediaStackPanelJsonRespond($payload, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 403 : 405);
    }
    $app = $_POST['app'] ?? '';
    $app = is_string($app) ? $app : '';
    $allowed = array_fill_keys(array_merge(array_keys(pmssMediaStackPanelAppDefinitionsRead()), array('cloudplow')), true);
    $installed = pmssMediaStackPanelExpectedAppIdsRead($home);
    if (is_file(pmssCustomerHomePath($home, '.bin/cloudplow/cloudplow/cloudplow.py'))) $installed['cloudplow'] = true;
    if (!isset($allowed[$app]) || !isset($installed[$app])) {
        $payload['message'] = 'Unknown or uninstalled media-stack app.';
        pmssMediaStackPanelJsonRespond($payload, 400);
    }
    $script = pmssCustomerHomePath($home, 'install-media-stack.sh');
    if (!is_file($script) || is_link($script) || !is_readable($script) || !pmssFrontendShellExecAvailable()) {
        $payload['message'] = 'Media stack installer is unavailable.';
        pmssMediaStackPanelJsonRespond($payload, 409);
    }
    $gate = $action === 'app-stop' ? array('ok' => true) : pmssMediaStackPanelRecoveryGateRead($home);
    if (!$gate['ok']) {
        $payload['message'] = $gate['message'];
        pmssMediaStackPanelJsonRespond($payload, 409);
    }
    $marker = pmssCustomerHomePath($home, '.'.$app.'Disable');
    if (is_link($marker) || (file_exists($marker) && !is_file($marker))) {
        $payload['message'] = 'App control marker is unsafe.';
        pmssMediaStackPanelJsonRespond($payload, 409);
    }
    if ($action === 'app-restart' && is_file($marker)) {
        $payload['message'] = 'Start this app before requesting a restart.';
        pmssMediaStackPanelJsonRespond($payload, 409);
    }
    if ($action === 'app-stop' && !@touch($marker)) {
        $payload['message'] = 'Could not save the stopped state.';
        pmssMediaStackPanelJsonRespond($payload, 500);
    }
    if ($action === 'app-start' && is_file($marker) && !@unlink($marker)) {
        $payload['message'] = 'Could not clear the stopped state.';
        pmssMediaStackPanelJsonRespond($payload, 500);
    }
    $flags = $action === 'app-start' ? array('--start-app='.$app)
        : ($action === 'app-stop' ? array('--stop-app='.$app) : array('--stop-app='.$app, '--start-app='.$app));
    foreach ($flags as $flag) {
        $command = 'cd '.escapeshellarg($home).' && HOME='.escapeshellarg($home)
            .' USER='.escapeshellarg($username).' LOGNAME='.escapeshellarg($username)
            .' /bin/bash '.escapeshellarg($script).' '.escapeshellarg($flag).' >/dev/null 2>&1 && printf %s pmss-ok';
        if (trim((string) pmssFrontendShellExec($command)) !== 'pmss-ok') {
            $payload['message'] = 'Could not '.$action.' '.$app.'.';
            pmssMediaStackPanelJsonRespond($payload, 500);
        }
    }
    $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
    $payload['message'] = ucfirst($app).' '.$action.' request completed.';
    pmssMediaStackPanelJsonRespond($payload, 202);
}

/** Start absent media-stack sessions once, without changing watchdog policy. */
function pmssMediaStackPanelRecoveryHandle($home, $username, $hostname)
{
    if (!pmssMediaStackPanelRecoveryRequestAllowed($_SERVER)) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = 'Starting stopped media-stack apps requires a panel POST request.';
        pmssMediaStackPanelJsonRespond($payload, (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? 403 : 405);
    }

    $gate = pmssMediaStackPanelRecoveryGateRead($home);
    if (!$gate['ok']) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = $gate['message'];
        pmssMediaStackPanelJsonRespond($payload, 409);
    }

    $result = pmssFrontendShellExec(pmssMediaStackPanelRecoveryCommandBuild($home, $username));
    $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
    if (trim((string) $result) !== 'pmss-media-stack-started') {
        $payload['message'] = 'Stopped media-stack apps could not be started from the panel.';
        pmssMediaStackPanelJsonRespond($payload, 500);
    }

    $payload['message'] = 'Start request sent for stopped media-stack apps.';
    pmssMediaStackPanelJsonRespond($payload, 202);
}

/** Apply one app's default auth through the guarded customer-side installer. */
function pmssMediaStackPanelSecureHandle($home, $username, $hostname)
{
    if (!pmssMediaStackPanelRecoveryRequestAllowed($_SERVER)) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = 'Securing a media-stack app requires a panel POST request.';
        pmssMediaStackPanelJsonRespond($payload, (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? 403 : 405);
    }

    $action = pmssFrontendActionRequest();
    $app = pmssMediaStackPanelSecureActionAppIdRead($action);
    if ($app === null) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = 'Unknown media-stack app.';
        pmssMediaStackPanelJsonRespond($payload, 400);
    }

    $label = pmssMediaStackPanelAppLabelRead($app);
    if (pmssMediaStackPanelAppAuthConfigured($home, $app)) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = $label.' is already protected.';
        pmssMediaStackPanelJsonRespond($payload);
    }

    $gate = pmssMediaStackPanelSecureGateRead($home, $app);
    if (!$gate['ok']) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = $gate['message'];
        pmssMediaStackPanelJsonRespond($payload, 409);
    }

    $command = pmssMediaStackPanelSecureCommandBuild($home, $username, $app);
    if ($command === '') {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = 'Unknown media-stack app.';
        pmssMediaStackPanelJsonRespond($payload, 400);
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(240);
    }

    $result = pmssFrontendShellExec($command);
    $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
    if (strpos((string) $result, 'pmss-media-stack-secured:'.$app) === false
        && !pmssMediaStackPanelAppAuthConfigured($home, $app)) {
        $payload['message'] = $label.' could not be secured from the panel.';
        pmssMediaStackPanelJsonRespond($payload, 500);
    }

    $payload['message'] = $label.' auth was configured. Use ~/.media-stack-credentials.txt for login.';
    pmssMediaStackPanelJsonRespond($payload, 202);
}

/**
 * Start the web-wrapped media installer when the first-run gate allows it.
 */
function pmssMediaStackPanelStartHandle($home, $username, $hostname)
{
    if (!pmssMediaStackPanelRecoveryRequestAllowed($_SERVER)) {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = 'Media stack install start requires POST.';
        $payload['canStart'] = true;
        $payload['poll'] = false;
        $payload['state'] = 'blocked';
        pmssMediaStackPanelJsonRespond($payload, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 403 : 405);
    }

    $status = pmssMediaStackPanelStatusRead($home, $username, $hostname);
    if (!empty($status['poll'])) {
        pmssMediaStackPanelJsonRespond(pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname), 202);
    }

    $gate = pmssMediaStackPanelStartGateRead($home);
    if (!$gate['ok']) {
        pmssMediaStackPanelJsonRespond(pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname), 409);
    }

    $logPath = pmssCustomerHomePath($home, '.install-media-stack.log');
    if (is_file($logPath)) {
        @rename($logPath, $logPath.'.previous');
    }

    @unlink(pmssCustomerHomePath($home, '.install-media-stack-web.pid'));
    @pmssFrontendShellExec(pmssMediaStackPanelStartCommandBuild($home, $username));

    $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
    if ($payload['state'] === 'ready') {
        $payload['message'] = 'Media stack install could not be started from the panel.';
        $payload['html'] = pmssMediaStackPanelHtmlBuild(array(
            'state' => 'failed',
            'message' => $payload['message'],
            'details' => array('Use SSH to run install-media-stack.sh directly if the panel cannot launch it.'),
            'tail' => '',
            'urls' => array(),
        ));
        $payload['state'] = 'failed';
        $payload['canStart'] = true;
        pmssMediaStackPanelJsonRespond($payload, 500);
    }

    pmssMediaStackPanelJsonRespond($payload, 202);
}

/**
 * Emit a small JSON response for the welcome page widget.
 */
function pmssMediaStackPanelJsonRespond(array $payload, $statusCode = 200)
{
    http_response_code((int) $statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}
