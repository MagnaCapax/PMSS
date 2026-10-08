<?php
/** Live, customer-owned Apps tab probes and bounded app logs. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/userMediaStackPanel.php';

/** Return the seven installer-managed IDs, including the local Cloudplow install. */
function pmssAppsMediaIdsRead(): array
{
    return array_merge(array_keys(pmssMediaStackPanelAppDefinitionsRead()), array('cloudplow'));
}

/** One tmux query covers every media app. */
function pmssAppsTmuxSessionsRead(): array
{
    $output = pmssFrontendShellExec("tmux list-sessions -F '#S' 2>/dev/null");
    $sessions = array();
    foreach (explode("\n", (string) $output) as $name) {
        if (in_array($name, pmssAppsMediaIdsRead(), true)) $sessions[$name] = true;
    }
    return $sessions;
}

/** Use current markers and processes, never a potentially stale watchdog snapshot. */
function pmssAppsLiveStatusRead(string $home, string $username, string $hostname): array
{
    $panel = pmssMediaStackPanelStatusRead($home, $username, $hostname);
    $installed = pmssMediaStackPanelExpectedAppIdsRead($home);
    if (is_file(pmssCustomerHomePath($home, '.bin/cloudplow/cloudplow/cloudplow.py'))) $installed['cloudplow'] = true;
    $sessions = $installed === array() ? array() : pmssAppsTmuxSessionsRead();
    $apps = array();
    foreach (pmssAppsMediaIdsRead() as $id) {
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
    foreach (pmssCustomerManagedAppDefinitions() as $name => $definition) {
        if (!pmssWelcomeServiceAvailable($definition['endpoint'], $definition['binaries'])) continue;
        $enabled = is_file(pmssCustomerHomePath($home, basename($definition['enable'])));
        $apps[$name] = array('kind' => 'managed', 'state' => !$enabled ? 'off'
            : (pmssCustomerNativePidsRead($definition['binaries']) !== array() ? 'running' : 'not-running'),
            'url' => $name === 'qBittorrent' ? 'qbittorrent/' : ($name === 'Deluge' ? 'deluge/' : 'rclone/'),
            'endpoint' => $definition['endpoint']);
    }
    $apps['lighttpd'] = array('kind' => 'web', 'state' => 'unknown');
    return array('apps' => $apps, 'installed' => $installed !== array(),
        'canStart' => !empty($panel['canStart']), 'canRestart' => !empty($panel['canRestart']),
        'poll' => !empty($panel['poll']), 'message' => (string) ($panel['message'] ?? ''));
}

/** Read only a fixed app log, cap work to 16 KiB and output to twenty short lines. */
function pmssAppsLogTailRead(string $home, string $app): string
{
    if (!in_array($app, pmssAppsMediaIdsRead(), true)) return '';
    $relative = $app === 'jellyfin' ? '.config/jellyfin/log/jellyfin.log'
        : '.config/'.$app.'/'.$app.'.log';
    $path = pmssCustomerHomePath($home, $relative);
    if (!is_file($path) || is_link($path)) return '';
    $size = @filesize($path);
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return '';
    if (is_int($size) && $size > 16384) @fseek($handle, -16384, SEEK_END);
    $raw = stream_get_contents($handle, 16384);
    @fclose($handle);
    if (!is_string($raw)) return '';
    $lines = array_slice(explode("\n", trim($raw)), -20);
    return implode("\n", array_map(static function ($line) {
        return htmlspecialchars(substr($line, 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $lines));
}
