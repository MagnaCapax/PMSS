<?php
/** AppsHtml helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerAppsDescriptionsRead')) {
/** Descriptions are display copy; app identity and URL come from existing readers. */
function pmssCustomerAppsDescriptionsRead(): array
{
    $descriptions = array('Jellyfin' => 'Stream your library to TV, phone and browser',
        'Sonarr' => 'Finds and downloads TV episodes', 'Radarr' => 'Finds and downloads movies',
        'Prowlarr' => 'Manages indexers for Sonarr and Radarr', 'SABnzbd' => 'Usenet downloader',
        'Autobrr' => 'Grabs releases from IRC announces', 'Cloudplow' => 'Moves finished files to cloud storage',
        'rTorrent + ruTorrent' => 'Your main torrent client', 'qBittorrent' => 'Second torrent client',
        'Deluge' => 'Second torrent client', 'Transmission' => 'Torrent client', 'rclone' => 'Sync to and from cloud storage',
        'OpenClaw' => 'AI agent with shell access; uses your Pulsed Media AI credits by default',
        );
    foreach (require __DIR__.'/toggleAppCatalog.php' as $row) $descriptions[$row['label']] = $row['description'];
    return $descriptions;
}
}

if (!function_exists('pmssCustomerAppRowStart')) {
/** The installed rows use one shared responsive markup shape. */
function pmssCustomerAppRowStart(string $name, string $description, string $id = ''): string
{
    return '<div class="row"'.($id === '' ? '' : ' data-app="'.pmssCustomerHtmlAttr($id).'"').'><div class="main"><div class="name">'.pmssCustomerHtmlAttr($name).'</div>'
        .($description === '' ? '' : '<div class="desc">'.pmssCustomerHtmlAttr($description).'</div>').'</div>';
}
}

if (!function_exists('pmssCustomerAppButton')) {
/** Small fixed-action buttons keep every valid control visible. */
function pmssCustomerAppButton(string $label, string $action, string $class = ''): string
{
    return '<button type="button" class="b '.$class.'" data-action="'.pmssCustomerHtmlAttr($action).'">'.pmssCustomerHtmlAttr($label).'</button>';
}
}

if (!function_exists('pmssCustomerAppRowHtmlBuild')) {
/** Render one row from the current live state. The browser replaces this row after polling. */
function pmssCustomerAppRowHtmlBuild(string $id, array $app, bool $mediaAppControl = true): string
{
    $names = array('jellyfin' => 'Jellyfin', 'sonarr' => 'Sonarr', 'radarr' => 'Radarr',
        'prowlarr' => 'Prowlarr', 'sabnzbd' => 'SABnzbd', 'autobrr' => 'Autobrr',
        'cloudplow' => 'Cloudplow', 'rtorrent' => 'rTorrent + ruTorrent',
        'qBittorrent' => 'qBittorrent', 'Deluge' => 'Deluge', 'rclone' => 'rclone',
        'lighttpd' => 'Web server', 'openclaw' => 'OpenClaw');
    foreach (require __DIR__.'/toggleAppCatalog.php' as $appId => $row) $names[$appId] = $row['label'];
    $name = $names[$id] ?? $id;
    $description = pmssCustomerAppsDescriptionsRead()[$name] ?? ($id === 'lighttpd' ? 'Your account web server' : '');
    $state = $app['state'];
    $label = $state === 'off' ? ($app['kind'] === 'managed' ? 'Off' : 'Stopped by you')
        : ($state === 'not-running' ? 'Not running' : ($state === 'running' ? 'Running'
            : ($state === 'not secured' ? 'Not secured' : ($state === 'gave up' ? 'Gave up' : ($state === 'stopped' ? 'Stopped' : 'Status unavailable')))));
    if ($state === 'installing') $label = 'Starting';
    if ($state === 'failed') $label = 'Failed';
    $pillClass = $state === 'running' ? 'p-run' : ($state === 'off' ? 'p-off' : 'p-stop');
    $html = pmssCustomerAppRowStart($name, $description, $id)
        .'<span class="pill '.$pillClass.'">'.pmssCustomerHtmlAttr($label).'</span>';
    if (!empty($app['exposed'])) $html .= '<span class="pill p-warn">No login set</span>';
    if (!empty($app['login'])) $html .= '<span class="desc">'.pmssCustomerHtmlAttr($app['loginLabel'] ?? 'Login').': <code>'.pmssCustomerHtmlAttr($app['login']).'</code></span>';
    $html .= '<div class="acts">';
    if (($state === 'running' || $app['kind'] === 'media') && !empty($app['url'])) {
        $html .= '<a class="b b-pri" href="'.pmssCustomerHtmlAttr($app['url']).'" target="_blank" rel="noopener">Open</a>';
    }
    if ($app['kind'] === 'media') {
        if ($mediaAppControl) {
            if ($state === 'running') $html .= pmssCustomerAppButton('Restart', 'restart').pmssCustomerAppButton('Stop', 'stop');
            else $html .= pmssCustomerAppButton('Start', 'start', 'b-pri').($state === 'not-running' ? pmssCustomerAppButton('Show log', 'log') : '');
        }
        if (!empty($app['canSecure']) && is_file(__DIR__.'/mediaStack.php')) $html .= pmssCustomerAppButton('Secure this app', 'secure', 'b-warn');
    } elseif ($app['kind'] === 'rtorrent') {
        if ($state === 'running') $html .= pmssCustomerAppButton('Restart', 'restart').pmssCustomerAppButton('Stop', 'stop');
        else $html .= pmssCustomerAppButton('Start', 'start', 'b-pri');
    } elseif ($app['kind'] === 'managed') {
        if ($state === 'running') $html .= pmssCustomerAppButton('Restart', 'restart').pmssCustomerAppButton('Turn off', 'disable');
        elseif ($state === 'off') $html .= pmssCustomerAppButton('Turn on', 'start', 'b-pri');
        elseif ($state === 'gave up') $html .= pmssCustomerAppButton('Restart', 'restart', 'b-pri').pmssCustomerAppButton('Turn off', 'disable');
        else $html .= pmssCustomerAppButton('Start', 'start', 'b-pri').pmssCustomerAppButton('Turn off', 'disable');
        if (!empty($app['log'])) $html .= pmssCustomerAppButton('Show log', 'log');
    } else {
        $html .= pmssCustomerAppButton('Restart', 'restart', 'b-pri');
    }
    return $html.'</div><span class="row-progress" aria-live="polite"></span><div class="row-error" role="alert"></div><pre class="row-log" hidden></pre></div>';
}
}

if (!function_exists('pmssCustomerAppsMediaRowsBuild')) {
/** The installer status takes precedence over partial app install markers. */
function pmssCustomerAppsMediaRowsBuild(array $status): string
{
    if (!empty($status['poll'])) {
        return pmssCustomerAppRowStart('Installing the media stack…', $status['message']).'</div>';
    }
    if (empty($status['installed'])) {
        $names = implode(', ', array_merge(function_exists('pmssMediaStackPanelAppDefinitionsRead')
            ? array_column(pmssMediaStackPanelAppDefinitionsRead(), 'label') : array(), array('Cloudplow')));
        return pmssCustomerAppRowStart('Media Stack is not installed', $names)
            .'<div class="desc" id="pmss-install-progress">'.pmssCustomerHtmlAttr($status['message']).'</div>'
            .'<div class="acts"><button type="button" class="b b-pri" onclick="pmssMediaStackStart(this)"'
            .(!empty($status['canStart']) && is_file(__DIR__.'/mediaStack.php') ? '' : ' disabled').'>Install Media Stack</button></div></div>';
    }
    $html = '';
    foreach (pmssCustomerAppsMediaIdsRead() as $id) {
        if (isset($status['apps'][$id])) $html .= pmssCustomerAppRowHtmlBuild($id, $status['apps'][$id], !empty($status['mediaAppControl']));
    }
    return $html;
}
}

if (!function_exists('pmssCustomerAppsSectionHtmlBuild')) {
/** Render the same app controls on Welcome and Apps. */
function pmssCustomerAppsSectionHtmlBuild(array $status, string $statusUrl = 'apps.php?status=1'): string
{
    $rows = array();
    foreach (array('rtorrent', 'qBittorrent', 'Deluge', 'Transmission', 'rclone', 'lighttpd') as $id) {
        if (isset($status['apps'][$id])) $rows[$id] = pmssCustomerAppRowHtmlBuild($id, $status['apps'][$id], !empty($status['mediaAppControl']));
    }
    $toggleRows = '';
    foreach (pmssCustomerManagedAppDefinitions() as $id => $row) {
        if (in_array($id, array('qBittorrent', 'Deluge', 'Transmission', 'rclone'), true)) continue;
        if (isset($status['apps'][$id])) $toggleRows .= pmssCustomerAppRowHtmlBuild($id, $status['apps'][$id]);
    }
    $startAll = !empty($status['installed']) && empty($status['poll']) && !empty($status['canRestart'])
        && is_file(__DIR__.'/mediaStack.php')
        ? '<button type="button" class="b" id="pmss-start-all" data-bulk-action="start-stopped">Start all stopped</button>' : '';
    return strtr('<section class="pmss-apps-section" data-status-url="STATUS_URL"><div class="apps-header"><h2>Your apps</h2><button type="button" class="b" onclick="pmssAppsRestartAll(this)">Restart all my services</button></div>
<div class="group">Media Stack</div><div class="section-actions">START_ALL</div><div class="list" id="pmss-media-apps" data-installing="INSTALLING">MEDIA_ROWS</div>
<div class="group">Torrent clients</div><div class="list" id="pmss-torrent-apps">TORRENT_ROWS</div>
<div class="group">Transfers</div><div class="list" id="pmss-transfer-apps">TRANSFER_ROWS</div>
<div class="group">Optional apps</div><div class="list" id="pmss-toggle-apps">TOGGLE_ROWS</div>
<div class="group">Web server</div><div class="list">WEB_ROW</div></section>', array('STATUS_URL' => pmssCustomerHtmlAttr($statusUrl),
        'START_ALL' => $startAll, 'INSTALLING' => !empty($status['poll']) ? '1' : '0',
        'MEDIA_ROWS' => pmssCustomerAppsMediaRowsBuild($status),
        'TORRENT_ROWS' => ($rows['rtorrent'] ?? '').($rows['qBittorrent'] ?? '').($rows['Deluge'] ?? '').($rows['Transmission'] ?? ''),
        'TRANSFER_ROWS' => $rows['rclone'] ?? '', 'TOGGLE_ROWS' => $toggleRows, 'WEB_ROW' => $rows['lighttpd'] ?? ''));
}
}

if (!function_exists('pmssCustomerAppsCss')) {
/** Shared responsive app-row styles for both guiv pages. */
function pmssCustomerAppsCss(): string
{
    return '<style>'.'
.pmss-apps-section{font-size:14px;line-height:1.45;background:#0b1220;color:#e6edf3;padding:14px;border-radius:8px;box-sizing:border-box}.pmss-apps-section h2{font-size:1.05rem;color:#fff;margin:0}.apps-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.pmss-apps-section .group{color:#9fb0c3;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 4px}.pmss-apps-section .list{border:1px solid #2e3b4f;border-radius:8px;overflow:hidden}
.pmss-apps-section .row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-top:1px solid #223042;background:#0f1b2b;flex-wrap:wrap}.pmss-apps-section .row:first-child{border-top:0}.pmss-apps-section .row .main{flex:1;min-width:0}.pmss-apps-section .row .name{font-weight:bold;color:#fff}.pmss-apps-section .row .desc{color:#9fb0c3;font-size:13px}
.pmss-apps-section .pill{font-size:12px;padding:2px 9px;border-radius:999px;white-space:nowrap}.p-run{background:#12351f;color:#8fd18f}.p-stop{background:#3a1d1d;color:#f19999}.p-off{background:#1f2937;color:#9fb0c3}.p-warn{background:#3a2e12;color:#f0b429}
.pmss-apps-section .acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.pmss-apps-section .b{font-size:13px;padding:5px 12px;border-radius:6px;border:1px solid #2e3b4f;background:#13293d;color:#e6edf3;text-decoration:none;white-space:nowrap;cursor:pointer}.pmss-apps-section .b-pri{background:#0e7490;border-color:#0e7490;color:#fff}.pmss-apps-section .b-warn{border-color:#6b5420;color:#f0b429;background:#2a2414}
.row-progress{color:#9fb0c3}.row-error{color:#f19999;width:100%}.row-progress:empty,.row-error:empty{display:none}.row-log{width:100%;max-height:240px;overflow:auto;white-space:pre-wrap;background:#0b1220;color:#e6edf3;padding:8px}.pmss-apps-section .section-actions{display:flex;gap:8px;margin:8px 0 12px}
#pmss-action-notice{display:none;position:fixed;top:10px;right:10px;z-index:9999;max-width:580px;padding:8px 12px;border:1px solid #2e3b4f;background:#13293d;color:#e6edf3;font-weight:bold}#pmss-action-notice.pmss-error{border-color:#f19999;background:#3a1d1d;color:#f19999}
@media(max-width:640px){.apps-header{align-items:flex-start;flex-direction:column}.pmss-apps-section .acts{width:100%;justify-content:flex-start}}
'.'</style>';
}
}
