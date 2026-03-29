<?php
/**
 * PMSS: Welcome-page media stack launcher endpoint.
 *
 * Starts the existing per-user install-media-stack.sh script and reports a
 * bounded status payload for the welcome page AJAX widget.
 *
 * Copyright (C) 2010-2026 Magna Capax Finland Oy
 */

require_once '/scripts/lib/user/mediaStackPanel.php';

$home = dirname(__DIR__);
$username = pmssMediaStackPanelCurrentUserRead($home);
$hostname = pmssMediaStackPanelCurrentHostnameRead();
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';

if ($action === 'start') {
    pmssMediaStackPanelStartHandle($home, $username, $hostname);
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
        'poll' => !empty($status['poll']),
        'state' => $status['state'],
    );
}

/**
 * Start the web-wrapped media installer when the first-run gate allows it.
 */
function pmssMediaStackPanelStartHandle($home, $username, $hostname)
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $payload = pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname);
        $payload['message'] = 'Media stack install start requires POST.';
        $payload['canStart'] = true;
        $payload['poll'] = false;
        $payload['state'] = 'blocked';
        pmssMediaStackPanelJsonRespond($payload, 405);
    }

    $status = pmssMediaStackPanelStatusRead($home, $username, $hostname);
    if (!empty($status['poll'])) {
        pmssMediaStackPanelJsonRespond(pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname), 202);
    }

    $gate = pmssMediaStackPanelStartGateRead($home);
    if (!$gate['ok']) {
        pmssMediaStackPanelJsonRespond(pmssMediaStackPanelStatusPayloadBuild($home, $username, $hostname), 409);
    }

    $logPath = pmssMediaStackPanelHomePath($home, '.install-media-stack.log');
    if (is_file($logPath)) {
        @rename($logPath, $logPath.'.previous');
    }

    @unlink(pmssMediaStackPanelHomePath($home, '.install-media-stack-web.pid'));
    @shell_exec(pmssMediaStackPanelStartCommandBuild($home, $username));

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
