<?php
/** Customer app controls and the closed, documentation-only Docker catalog. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/userMediaStackPanel.php';

/** Escape every dynamic value used in text, attributes, and inline handlers. */
function pmssAppsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** The wiki catalog keeps the existing category and native-install metadata. */
function pmssAppsCatalogRead(): array
{
    return array(
        'Media servers and live TV' => array(
            array('Tunarr', true, 'Builds live-TV channels from a Jellyfin, Plex or Emby library', 'no login'),
            array('Channels DVR', true, 'Records live TV from tuners or IPTV for the Channels apps', ''),
            array('Threadfin', true, 'Presents M3U/XMLTV playlists as a tuner to Jellyfin, Plex or Emby', ''),
            array('Koel', true, 'Streams your own music collection in the browser', ''),
            array('mStream', true, 'Music streaming server with a web player and mobile apps', ''),
        ),
        'Comics, books and audiobooks' => array(
            array('Komga', true, 'Comics, manga and ebook server with a web reader', ''),
            array('Ubooquity', true, 'Comic and ebook server with a browser reader', ''),
            array('Calibre', true, 'The full Calibre ebook manager as a desktop in the browser', ''),
            array('Calibre-Web Automated', false, 'Web library that imports and converts dropped books', ''),
            array('BookLore', false, 'Ebook and comic library with shelves and device sync', ''),
            array('Grimmory', false, 'Community fork of BookLore, adds audiobooks', ''),
            array('Storyteller', false, 'Lines up an audiobook with its ebook text', '8 GB RAM'),
        ),
        'Downloads and requests' => array(
            array('qui', true, 'One web interface for several qBittorrent instances', ''),
            array('JDownloader 2', true, 'Download manager for direct links and file hosts', ''),
            array('slskd', true, 'Web client for the Soulseek network', ''),
            array('Seerr', true, 'Media requests for Jellyfin, Plex and Emby', ''),
            array('DroppedNeedle', false, 'Music requests that drive your own slskd or SABnzbd', ''),
            array('ReadMeABook', false, 'Audiobook requests: search, download, merge, import', ''),
        ),
        'Library automation' => array(
            array('Scryer', true, 'Movie, TV and anime automation in one program', ''),
            array('Medusa', true, 'TV library manager in the Sick-Beard family', ''),
            array('SickGear', true, 'TV and anime library manager', ''),
            array('Headphones', true, 'Music library downloader, predecessor of Lidarr', ''),
            array('Mylar3', true, 'Follows comic series and fetches new issues', ''),
            array('Bindery', true, 'Follows authors and fetches new ebooks and audiobooks', ''),
            array('Profilarr', false, 'Builds and syncs quality profiles to Sonarr and Radarr', ''),
        ),
        'Library maintenance and transcoding' => array(
            array('Maintainerr', false, 'Rule-based cleanup of old titles in your media server', 'no login'),
            array('Cleanuparr', true, 'Clears stalled and failed downloads from queues', 'deletes files'),
            array('Deleterr', true, 'Scheduled deletion of watched or stale media', 'deletes files'),
            array('Tdarr', true, 'Batch transcoding and remuxing with FFmpeg', ''),
            array('Unmanic', true, 'Watches a library and runs FFmpeg jobs', 'no login'),
            array('tinyMediaManager', true, 'Scrapes metadata and artwork, renames files', ''),
        ),
        'Photos, files and passwords' => array(
            array('Immich', false, 'Photo and video library with phone backup', '6 GB+ RAM'),
            array('PhotoPrism', true, 'Indexes, tags and searches photos and videos', '3 GB+ RAM'),
            array('Copyparty', true, 'File server with resumable uploads and share links', ''),
            array('Vaultwarden', false, 'Password server for the Bitwarden apps', ''),
        ),
        'Dashboards, chat and desktop' => array(
            array('Organizr', true, 'Puts your other apps behind one login as tabs', ''),
            array('The Lounge', true, 'Always-on IRC client in the browser', ''),
            array('Mattermost', true, 'Team chat with channels and file sharing', ''),
            array('Webtop', false, 'A full Linux desktop in a browser tab', ''),
        ),
    );
}

/** Build closed wiki and managed-client destinations. */
function pmssAppsAllowedUrlsRead(): array
{
    $urls = array('stylesheet' => 'screen.css', 'rutorrent' => 'rutorrent/',
        'qBittorrent' => 'qbittorrent/', 'Deluge' => 'deluge/', 'rclone' => 'rclone/');
    $base = 'https://wiki.pulsedmedia.com/index.php/Self-Hosted_Apps_on_PMSS#';
    foreach (array_keys(pmssAppsCatalogRead()) as $category) {
        $urls[$category] = $base.str_replace(' ', '_', $category);
    }
    return $urls;
}

/** Descriptions are display copy; app identity and URL come from existing readers. */
function pmssAppsDescriptionsRead(): array
{
    return array('Jellyfin' => 'Stream your library to TV, phone and browser',
        'Sonarr' => 'Finds and downloads TV episodes', 'Radarr' => 'Finds and downloads movies',
        'Prowlarr' => 'Manages indexers for Sonarr and Radarr', 'SABnzbd' => 'Usenet downloader',
        'Autobrr' => 'Grabs releases from IRC announces', 'Cloudplow' => 'Moves finished files to cloud storage',
        'rTorrent + ruTorrent' => 'Your main torrent client', 'qBittorrent' => 'Second torrent client',
        'Deluge' => 'Second torrent client', 'rclone' => 'Sync to and from cloud storage');
}

/** The installed rows use one shared responsive markup shape. */
function pmssAppsRowStart(string $name, string $description, string $id = ''): string
{
    return '<div class="row"'.($id === '' ? '' : ' data-app="'.pmssAppsEscape($id).'"').'><div class="main"><div class="name">'.pmssAppsEscape($name).'</div>'
        .($description === '' ? '' : '<div class="desc">'.pmssAppsEscape($description).'</div>').'</div>';
}

/** Small fixed-action buttons keep every valid control visible. */
function pmssAppsButton(string $label, string $action, string $class = ''): string
{
    return '<button type="button" class="b '.$class.'" data-action="'.pmssAppsEscape($action).'">'.pmssAppsEscape($label).'</button>';
}

/** Render one row from the current live state. The browser replaces this row after polling. */
function pmssAppsLiveRowBuild(string $id, array $app): string
{
    $names = array('jellyfin' => 'Jellyfin', 'sonarr' => 'Sonarr', 'radarr' => 'Radarr',
        'prowlarr' => 'Prowlarr', 'sabnzbd' => 'SABnzbd', 'autobrr' => 'Autobrr',
        'cloudplow' => 'Cloudplow', 'rtorrent' => 'rTorrent + ruTorrent',
        'qBittorrent' => 'qBittorrent', 'Deluge' => 'Deluge', 'rclone' => 'rclone',
        'lighttpd' => 'Web server');
    $name = $names[$id] ?? $id;
    $description = pmssAppsDescriptionsRead()[$name] ?? ($id === 'lighttpd' ? 'Your account web server' : '');
    $state = $app['state'];
    $label = $state === 'off' ? ($app['kind'] === 'managed' ? 'Off' : 'Stopped by you')
        : ($state === 'not-running' ? 'Not running' : ($state === 'running' ? 'Running' : 'Status unavailable'));
    $pillClass = $state === 'running' ? 'p-run' : ($state === 'off' ? 'p-off' : 'p-stop');
    $html = pmssAppsRowStart($name, $description, $id)
        .'<span class="pill '.$pillClass.'">'.pmssAppsEscape($label).'</span>';
    if (!empty($app['exposed'])) $html .= '<span class="pill p-warn">No login set</span>';
    $html .= '<div class="acts">';
    if ($state === 'running' && !empty($app['url'])) {
        $html .= '<a class="b b-pri" href="'.pmssAppsEscape($app['url']).'" target="_blank" rel="noopener">Open</a>';
    }
    if ($app['kind'] === 'media') {
        if ($state === 'running') $html .= pmssAppsButton('Restart', 'restart').pmssAppsButton('Stop', 'stop');
        else $html .= pmssAppsButton('Start', 'start', 'b-pri').($state === 'not-running' ? pmssAppsButton('Show log', 'log') : '');
        if (!empty($app['canSecure'])) $html .= pmssAppsButton('Secure this app', 'secure', 'b-warn');
    } elseif ($app['kind'] === 'rtorrent') {
        if ($state === 'running') $html .= pmssAppsButton('Restart', 'restart').pmssAppsButton('Stop', 'stop');
        else $html .= pmssAppsButton('Start', 'start', 'b-pri');
    } elseif ($app['kind'] === 'managed') {
        if ($state === 'running') $html .= pmssAppsButton('Restart', 'restart').pmssAppsButton('Turn off', 'disable');
        elseif ($state === 'off') $html .= pmssAppsButton('Turn on', 'start', 'b-pri');
        else $html .= pmssAppsButton('Start', 'start', 'b-pri').pmssAppsButton('Turn off', 'disable');
    } else {
        $html .= pmssAppsButton('Restart', 'restart', 'b-pri');
    }
    return $html.'</div><span class="row-progress" aria-live="polite"></span><div class="row-error" role="alert"></div><pre class="row-log" hidden></pre></div>';
}

require_once __DIR__.'/appsRuntime.php';
if (!function_exists('pmssAppsRuntimeReady') || !pmssAppsRuntimeReady()) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Apps</title></head><body><p>The Apps page is being updated on this server. Reload it in a few minutes.</p></body></html>';
    return;
}
$home = dirname(__DIR__);
$username = basename(rtrim($home, '/'));
$hostname = function_exists('gethostname') ? (string) gethostname() : '';
$hostname = $hostname !== '' ? $hostname : (string) php_uname('n');
if (isset($_GET['log'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $app = is_string($_GET['log']) ? $_GET['log'] : '';
    if (!in_array($app, pmssAppsMediaIdsRead(), true)) { http_response_code(400); echo '{}'; return; }
    echo json_encode(array('app' => $app, 'html' => pmssAppsLogTailRead($home, $app)));
    return;
}
$status = pmssAppsLiveStatusRead($home, $username, $hostname);
if (isset($_GET['status'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $rows = array();
    foreach ($status['apps'] as $id => $app) $rows[$id] = pmssAppsLiveRowBuild($id, $app);
    $status['rows'] = $rows;
    echo json_encode($status);
    return;
}
$urls = pmssAppsAllowedUrlsRead();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Apps</title>
<link href="screen.css" rel="stylesheet" media="screen">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script><script><?= pmssActionScriptJs() ?></script>
<style>
.apps{font-size:14px;line-height:1.45;max-width:980px;margin:0 auto}.apps h1{font-size:1.5rem;margin:4px 0 14px}.apps h2{font-size:1.05rem;color:#fff;margin:24px 0 8px}
.group{color:#9fb0c3;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 4px}.list{border:1px solid #2e3b4f;border-radius:8px;overflow:hidden}
.row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-top:1px solid #223042;background:#0f1b2b;flex-wrap:wrap}.row:first-child{border-top:0}.row .main{flex:1;min-width:0}.row .name{font-weight:bold;color:#fff}.row .desc{color:#9fb0c3;font-size:13px}
.pill{font-size:12px;padding:2px 9px;border-radius:999px;white-space:nowrap}.p-run{background:#12351f;color:#8fd18f}.p-stop{background:#3a1d1d;color:#f19999}.p-off{background:#1f2937;color:#9fb0c3}.p-warn{background:#3a2e12;color:#f0b429}
.acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.b{font-size:13px;padding:5px 12px;border-radius:6px;border:1px solid #2e3b4f;background:#13293d;color:#e6edf3;text-decoration:none;white-space:nowrap;cursor:pointer}.b-pri{background:#0e7490;border-color:#0e7490;color:#fff}.b-warn{border-color:#6b5420;color:#f0b429;background:#2a2414}
.search{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:8px;border:1px solid #2e3b4f;background:#0b1220;color:#e6edf3;font-size:14px;margin:4px 0 10px}.note{color:#9fb0c3;font-size:13px;margin:0 0 8px}.mrow{display:flex;gap:12px;align-items:baseline;padding:7px 14px;border-top:1px solid #223042;background:#0f1b2b}.mrow:first-child{border-top:0}.mrow .name{font-weight:bold;color:#fff;min-width:170px}.mrow .desc{flex:1;color:#9fb0c3;font-size:13px}.mrow .tag{font-size:11px;color:#f0b429;margin-left:6px}.mrow a{white-space:nowrap;font-size:13px}
.row-progress{color:#9fb0c3}.row-error{color:#f19999;width:100%}.row-log{width:100%;max-height:240px;overflow:auto;white-space:pre-wrap;background:#0b1220;color:#e6edf3;padding:8px}.section-actions{display:flex;gap:8px;margin:8px 0 12px}.apps details summary{cursor:pointer;color:#fff;font-weight:bold;margin:24px 0 10px}
#pmss-action-notice{display:none;position:fixed;top:10px;right:10px;z-index:9999;max-width:580px;padding:8px 12px;border:1px solid #2e3b4f;background:#13293d;color:#e6edf3;font-weight:bold}#pmss-action-notice.pmss-error{border-color:#f19999;background:#3a1d1d;color:#f19999}
@media(max-width:640px){.acts{width:100%;justify-content:flex-start}.mrow{flex-wrap:wrap}.mrow .name{min-width:0}}
</style></head><body><div id="pmss-action-notice" role="status" aria-live="polite"></div>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body"><div class="apps">
<h1>Apps</h1><h2>Your apps</h2>
<div class="group">Media Stack</div><div class="section-actions">
<?php if ($status['installed']): ?><button type="button" class="b" id="pmss-start-all" data-bulk-action="start-stopped">Start all stopped</button><?php endif; ?>
<button type="button" class="b" onclick="pmssAppsRestartAll(this)">Restart all my services</button></div><div class="list" id="pmss-media-apps">
<?php if (!$status['installed']): ?><div class="row"><div class="main"><div class="name">Media Stack is not installed</div><div class="desc"><?= pmssAppsEscape(implode(', ', array_merge(array_column(pmssMediaStackPanelAppDefinitionsRead(), 'label'), array('Cloudplow')))) ?></div><div class="desc" id="pmss-install-progress"><?= pmssAppsEscape($status['message']) ?></div></div><div class="acts"><button type="button" class="b b-pri" onclick="pmssAppsInstall(this)"<?= $status['canStart'] ? '' : ' disabled' ?>>Install Media Stack</button></div></div><?php else: foreach (pmssAppsMediaIdsRead() as $id): if (isset($status['apps'][$id])) echo pmssAppsLiveRowBuild($id, $status['apps'][$id]); endforeach; endif; ?>
</div><div class="group">Torrent clients</div><div class="list" id="pmss-torrent-apps">
<?php foreach (array('rtorrent','qBittorrent','Deluge') as $id): if (isset($status['apps'][$id])) echo pmssAppsLiveRowBuild($id, $status['apps'][$id]); endforeach; ?>
</div><div class="group">Transfers</div><div class="list" id="pmss-transfer-apps"><?php if (isset($status['apps']['rclone'])) echo pmssAppsLiveRowBuild('rclone', $status['apps']['rclone']); ?></div>
<div class="group">Web server</div><div class="list"><?= pmssAppsLiveRowBuild('lighttpd', $status['apps']['lighttpd']) ?></div>
<details><summary>Find more apps (39)</summary><p class="note">39 more apps you can set up yourself with rootless Docker. Each needs its own login turned on, because other accounts on this server can reach its port.</p><input id="pmss-apps-search" class="search" type="search" aria-label="Search apps" placeholder="Search apps, e.g. comics, photos, IRC"><div class="list" id="pmss-more-apps">
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?><div class="mrow"><span class="name"><?= pmssAppsEscape($app[0]) ?><?php if ($app[3] !== ''): ?><span class="tag"><?= pmssAppsEscape($app[3]) ?></span><?php endif; ?></span><span class="desc"><?= pmssAppsEscape($app[2]) ?></span><a href="<?= pmssAppsEscape($urls[$category]) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div><?php endforeach; endforeach; ?></div></details>
</div></div><div class="full_bottom"></div></div></div>
<script>
var pmssAppsBusy = {};
function pmssAppsRefresh(callback) {
    $.getJSON('apps.php?status=1', function(payload) {
        $.each(payload.rows || {}, function(id, html) {
            if (pmssAppsBusy[id]) return;
            var oldRow = $('[data-app="' + id + '"]');
            if (oldRow.length) {
                var oldLog = oldRow.find('.row-log');
                var replacement = $(html);
                if (!oldLog.prop('hidden')) replacement.find('.row-log').html(oldLog.html()).prop('hidden', false);
                oldRow.replaceWith(replacement);
            }
        });
        if (callback) callback(payload);
    });
}
function pmssAppsWait(id, desired, deadline) {
    window.setTimeout(function() {
        $.getJSON('apps.php?status=1', function(payload) {
            var row = $('[data-app="' + id + '"]');
            var state = payload.apps && payload.apps[id] && payload.apps[id].state;
            if (state === desired || Date.now() >= deadline) {
                pmssAppsBusy[id] = false;
                if (payload.rows && payload.rows[id]) row.replaceWith(payload.rows[id]);
                if (state !== desired) $('[data-app="' + id + '"] .row-error').text('Status did not settle within 60 seconds. Check the app log.');
            } else {
                pmssAppsWait(id, desired, deadline);
            }
        }).fail(function() { if (Date.now() < deadline) pmssAppsWait(id, desired, deadline); else { pmssAppsBusy[id] = false; $('[data-app="' + id + '"] .row-error').text('Could not read app status.'); } });
    }, 2000);
}
function pmssAppsAct(button, id, action) {
    var row = $(button).closest('.row'), kind = id === 'rtorrent' ? 'rtorrent' : (id === 'lighttpd' ? 'web' : (id === 'qBittorrent' || id === 'Deluge' || id === 'rclone' ? 'managed' : 'media'));
    if (action === 'log') { $.getJSON('apps.php?log=' + encodeURIComponent(id), function(payload) { row.find('.row-log').html(payload.html || 'No log available.').prop('hidden', false); }); return; }
    if (id === 'rtorrent' && action === 'stop' && !window.confirm('Stop rTorrent? All your torrents stop seeding until you start it again.')) return;
    if (action === 'secure') { if (!window.confirm('Secure this app with its default login?')) return; pmssMediaStackSecureApp(button, id); return; }
    var endpoint = kind === 'media' ? 'mediaStack.php?action=app-' + action : (kind === 'rtorrent' ? 'rtorrentRestart.php?action=' + action : (kind === 'web' ? 'lighttpdRestart.php?action=confirm-restart' : (id === 'qBittorrent' ? 'qbittorrent.php' : id === 'Deluge' ? 'deluge.php' : 'rclone.php') + '?action=' + action));
    var desired = action === 'stop' || action === 'disable' ? 'off' : 'running';
    pmssAppsBusy[id] = true;
    row.find('.row-error').text(''); row.find('.row-progress').text(action === 'stop' || action === 'disable' ? 'Stopping…' : 'Starting…');
    row.find('button').prop('disabled', true);
    pmssActionRequest({url:endpoint, data:kind === 'media' ? {app:id} : null, passwordField:id === 'qBittorrent' ? 'qbittorrentPassword' : ''}).done(function() {
        if (kind === 'web') { pmssAppsBusy[id] = false; row.find('.row-progress').text('Restart requested.'); row.find('button').prop('disabled', false); return; }
        pmssAppsWait(id, desired, Date.now() + 60000);
    }).fail(function(xhr, cancelled) {
        pmssAppsBusy[id] = false; row.find('.row-progress').text(''); row.find('button').prop('disabled', false);
        if (!cancelled) row.find('.row-error').text((xhr.responseJSON && xhr.responseJSON.message) || 'Action failed. Please try again.');
    });
}
$(document).on('click', '.row[data-app] button[data-action]', function() { pmssAppsAct(this, $(this).closest('.row').attr('data-app'), $(this).attr('data-action')); });
$(document).on('click', '#pmss-start-all[data-bulk-action]', function() { pmssAppsBulk(this, $(this).attr('data-bulk-action')); });
function pmssAppsBulk(button, action) { pmssMediaStackAction(button, action, 'Starting stopped apps…', 'Could not start stopped apps.'); }
function pmssAppsInstall(button) { pmssMediaStackStart(button); pmssAppsInstallPoll(0); }
function pmssAppsInstallPoll(elapsed) {
    if (elapsed >= 3600000) return;
    window.setTimeout(function() {
        $.getJSON('mediaStack.php?action=status', function(payload) {
            $('#pmss-install-progress').text(payload.message || 'Installing Media Stack…');
            if (payload.state === 'failed') return;
            $.getJSON('apps.php?status=1', function(status) {
                if (!status.installed || payload.poll) { pmssAppsInstallPoll(elapsed + 4000); return; }
                var ids = ['jellyfin','sonarr','radarr','prowlarr','sabnzbd','autobrr','cloudplow'], html = '';
                $.each(ids, function(_, id) { if (status.rows[id]) html += status.rows[id]; });
                $('#pmss-media-apps').html(html);
                if (!$('#pmss-start-all').length) $('.section-actions').prepend('<button type="button" class="b" id="pmss-start-all" data-bulk-action="start-stopped">Start all stopped</button>');
            });
        });
    }, 4000);
}
function pmssAppsRestartAll(button) {
    $(button).prop('disabled', true);
    $.getJSON('apps.php?status=1', function(status) {
        var actions = [], failures = 0, apps = status.apps || {};
        if (apps.rtorrent && apps.rtorrent.state !== 'off') actions.push({url:'rtorrentRestart.php?action=restart'});
        $.each({qBittorrent:'qbittorrent.php', Deluge:'deluge.php', rclone:'rclone.php'}, function(id, endpoint) {
            if (apps[id] && apps[id].state !== 'off') actions.push({url:endpoint + '?action=restart', passwordField:id === 'qBittorrent' ? 'qbittorrentPassword' : ''});
        });
        if (status.installed) actions.push({url:'mediaStack.php?action=start-stopped'});
        actions.push({url:'lighttpdRestart.php?action=confirm-restart'});
        function next() {
            if (!actions.length) {
                $(button).prop('disabled', false);
                pmssShowActionNotice(failures ? 'Some restart requests failed.' : 'Restart requests sent.', !!failures);
                pmssAppsRefresh();
                return;
            }
            pmssActionRequest(actions.shift()).done(next).fail(function() { failures++; next(); });
        }
        next();
    }).fail(function() { $(button).prop('disabled', false); pmssShowActionNotice('Could not read current app status.', true); });
}
window.pmssAppsActionComplete = function() { pmssAppsRefresh(); };
window.setInterval(pmssAppsRefresh, 10000);
$('#pmss-apps-search').on('input', function() { var query = this.value.toLowerCase().trim(); $('#pmss-more-apps .mrow').each(function() { $(this).toggle($(this).text().toLowerCase().indexOf(query) !== -1); }); });
</script></body></html>
