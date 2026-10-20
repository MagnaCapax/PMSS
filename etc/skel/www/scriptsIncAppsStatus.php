<?php
/** AppsStatus helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerAppsTmuxSessionsParse')) {
/** Accept only a single pane launched from the installer's app directory. */
function pmssCustomerAppsTmuxSessionsParse(string $output): array
{
    $paths = array('cloudplow' => array('/.bin/cloudplow', 'cloudplow.py'));
    foreach (pmssMediaStackPanelAppDefinitionsRead() as $app => $definition) {
        $files = $definition['markers']['file'] ?? $definition['secureMarkers']['file'];
        $paths[$app] = array('/'.dirname($files[0]), basename($files[0]));
    }
    $sessions = array();
    $counts = array();
    foreach (explode("\n", trim($output)) as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) !== 2 || !isset($paths[$parts[0]])) continue;
        $name = $parts[0];
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        if (strpos($parts[1], $paths[$name][0]) !== false
            && strpos($parts[1], $paths[$name][1]) !== false) $sessions[$name] = true;
    }
    foreach ($counts as $name => $count) if ($count !== 1) unset($sessions[$name]);
    return $sessions;
}
}

if (!function_exists('pmssCustomerAppsTmuxSessionsRead')) {
/** One tmux query covers every media app without trusting a session name alone. */
function pmssCustomerAppsTmuxSessionsRead(): array
{
    $output = pmssFrontendShellExec("tmux list-panes -a -F '#{session_name}|#{pane_start_command}' 2>/dev/null");
    return function_exists('pmssMediaStackPanelAppDefinitionsRead')
        ? pmssCustomerAppsTmuxSessionsParse((string) $output) : array();
}
}

if (!function_exists('pmssCustomerAppsStatusRead')) {
/** Use current markers and processes, never a potentially stale watchdog snapshot. */
function pmssCustomerAppsStatusRead(string $home, string $username, string $hostname): array
{
    $panel = function_exists('pmssMediaStackPanelStatusRead')
        ? pmssMediaStackPanelStatusRead($home, $username, $hostname) : array();
    $installed = function_exists('pmssMediaStackPanelExpectedAppIdsRead')
        ? pmssMediaStackPanelExpectedAppIdsRead($home) : array();
    if (function_exists('pmssMediaStackPanelAppDefinitionsRead')
        && is_file(pmssCustomerHomePath($home, '.bin/cloudplow/cloudplow/cloudplow.py'))) $installed['cloudplow'] = true;
    $sessions = $installed === array() ? array() : pmssCustomerAppsTmuxSessionsRead();
    $apps = array();
    foreach (pmssCustomerAppsMediaIdsRead() as $id) {
        if (!isset($installed[$id])) continue;
        $marker = pmssCustomerHomePath($home, '.'.$id.'Disable');
        $security = $panel['security'][$id] ?? array();
        $apps[$id] = array(
            'kind' => 'media',
            'state' => is_file($marker) ? 'off' : (isset($sessions[$id]) ? 'running' : 'not-running'),
            'url' => (string) ($security['url'] ?? ''),
            'security' => (string) ($security['status'] ?? 'Unavailable'),
            'protected' => !empty($security['protected']),
            'exposed' => $security !== array() && empty($security['protected']),
            'canSecure' => !empty($security['canSecure']),
        );
    }
    $rtorrentOff = is_file(pmssCustomerHomePath($home, '.rtorrentDisable'));
    $apps['rtorrent'] = array('kind' => 'rtorrent', 'state' => $rtorrentOff ? 'off'
        : (pmssCustomerNativePidsRead(array('/usr/bin/rtorrent', '/usr/local/bin/rtorrent')) !== array() ? 'running' : 'not-running'), 'url' => 'rutorrent/');
    $toggleCatalog = require __DIR__.'/toggleAppCatalog.php';
    foreach (pmssCustomerManagedAppDefinitions() as $name => $definition) {
        if (isset($definition['portMarker']) && !is_file(pmssCustomerHomePath($home, $definition['portMarker']))) continue;
        if (empty($definition['installable']) && !pmssWelcomeServiceAvailable($definition['endpoint'], $definition['binaries'])) continue;
        $enabled = is_file(pmssCustomerHomePath($home, preg_replace('#^\.\./#', '', $definition['enable'])));
        if (isset($definition['disable']) && is_file(pmssCustomerHomePath($home, $definition['disable']))) $enabled = false;
        $apps[$name] = array('kind' => 'managed', 'state' => !$enabled ? 'off'
            : (pmssCustomerNativePidsRead($definition['binaries']) !== array() ? 'running' : 'not-running'),
            'url' => $name === 'qBittorrent' ? 'qbittorrent/' : ($name === 'Deluge' ? 'deluge/' : ($name === 'rclone' ? 'rclone/' : $name.'/')),
            'endpoint' => $definition['endpoint']);
        if (isset($definition['stateFile'])) {
            $state = trim((string) @file_get_contents(pmssCustomerHomePath($home, $definition['stateFile'])));
            if ($enabled) $apps[$name]['state'] = strpos($state, 'gave_up ') === 0 ? 'gave up'
                : (strpos($state, 'failed ') === 0 ? 'failed' : 'not-running');
        }
        foreach (array('url', 'log', 'consent', 'waitSeconds') as $field) {
            if (isset($definition[$field])) $apps[$name][$field] = $definition[$field];
        }
        if (isset($definition['statusHelperFile'], $definition['statusHelper'])) {
            pmssWelcomeRequireLocalHelper($definition['statusHelperFile']);
            if (function_exists($definition['statusHelper'])) $apps[$name]['state'] = $definition['statusHelper']($home, $apps[$name]['state']);
        }
        if (isset($toggleCatalog[$name])) {
            $row = $toggleCatalog[$name];
            $state = @file_get_contents(pmssCustomerHomePath($home, $row['stateFile']));
            if ($enabled) $apps[$name]['state'] = !is_file(pmssCustomerHomePath($home, $row['securedMarker'])) ? 'not secured'
                : ($apps[$name]['state'] === 'running' ? 'running' : (trim((string) $state) === 'gave up' ? 'gave up' : 'stopped'));
            $login = pmssCustomerHomePath($home, $row['loginFile']);
            if (is_file($login) && !is_link($login) && (@fileperms($login) & 0777) === 0600
                && function_exists('posix_geteuid') && @fileowner($login) === posix_geteuid()) {
                $apps[$name]['login'] = trim((string) @file_get_contents($login));
                if (isset($row['loginLabel'])) $apps[$name]['loginLabel'] = $row['loginLabel'];
            }
        }
    }
    // This page is served by the account's lighttpd, so a successful request proves it is running.
    $apps['lighttpd'] = array('kind' => 'web', 'state' => 'running');
    return array('apps' => $apps, 'installed' => $installed !== array(),
        'canStart' => !empty($panel['canStart']) && is_file(__DIR__.'/mediaStack.php'),
        'canRestart' => !empty($panel['canRestart']) && is_file(__DIR__.'/mediaStack.php'),
        'poll' => !empty($panel['poll']), 'message' => (string) ($panel['message'] ?? ''),
        'mediaAppControl' => is_file(__DIR__.'/appsRuntime.php'));
}
}

if (!function_exists('pmssCustomerAppsStatusJsonEmit')) {
/** Send one read-only status shape from either customer page. */
function pmssCustomerAppsStatusJsonEmit(): void
{
    pmssWelcomeRequireLocalHelper('userMediaStackPanel.php');
    list($home, $username, $hostname) = pmssCustomerAccountContext();
    $status = pmssCustomerAppsStatusRead($home, $username, $hostname);
    $rows = array();
    foreach ($status['apps'] as $id => $app) {
        if ($status['poll'] && $app['kind'] === 'media') continue;
        $rows[$id] = pmssCustomerAppRowHtmlBuild($id, $app, $status['mediaAppControl']);
    }
    $status['rows'] = $rows;
    $status['mediaHtml'] = pmssCustomerAppsMediaRowsBuild($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($status);
}
}

if (!function_exists('pmssCustomerAppsLogJsonEmit')) {
/** Serve bounded media logs through either page when the newer runtime is installed. */
function pmssCustomerAppsLogJsonEmit(): void
{
    pmssWelcomeRequireLocalHelper('appsRuntime.php');
    $app = is_string($_GET['log'] ?? null) ? $_GET['log'] : '';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!function_exists('pmssAppsLogTailRead') || (!in_array($app, pmssCustomerAppsMediaIdsRead(), true) && empty(pmssCustomerManagedAppDefinitions()[$app]['log']))) {
        http_response_code(400);
        echo '{}';
        return;
    }
    echo json_encode(array('app' => $app, 'html' => pmssAppsLogTailRead(dirname(__DIR__), $app)));
}
}
