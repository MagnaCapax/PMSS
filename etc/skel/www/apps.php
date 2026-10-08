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

/** Build a fixed-endpoint action using the Welcome page's request flow. */
function pmssAppsActionButtonBuild(string $label, string $url, string $success, bool $reload, string $pending, string $class = ''): string
{
    $args = array($url, $success, $reload, $pending);
    $js = array_map(static function ($value) { return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }, $args);
    return '<button type="button" class="b '.$class.'" onclick="pmssRunAction(this, '.pmssAppsEscape(implode(', ', $js)).');">'.pmssAppsEscape($label).'</button>';
}

/** The installed rows use one shared responsive markup shape. */
function pmssAppsRowStart(string $name, string $description): string
{
    return '<div class="row"><div class="main"><div class="name">'.pmssAppsEscape($name).'</div>'
        .($description === '' ? '' : '<div class="desc">'.pmssAppsEscape($description).'</div>').'</div>';
}

/** The watchdog snapshot is authoritative for media app runtime state. */
function pmssAppsRuntimeStateRead(?array $runtime, string $id): string
{
    $state = (string) ($runtime['apps'][$id]['state'] ?? 'unknown');
    return in_array($state, array('running', 'stopped', 'failed'), true) ? $state : 'unknown';
}

$home = dirname(__DIR__);
$username = basename(rtrim($home, '/'));
$hostname = function_exists('gethostname') ? (string) gethostname() : '';
$hostname = $hostname !== '' ? $hostname : (string) php_uname('n');
$mediaStackStatus = pmssMediaStackPanelStatusRead($home, $username, $hostname);
$runtime = pmssMediaStackPanelRuntimeStatusRead($home);
$installedIds = pmssMediaStackPanelExpectedAppIdsRead($home);
$managedApps = pmssCustomerManagedAppDefinitions();
$descriptions = pmssAppsDescriptionsRead();
$urls = pmssAppsAllowedUrlsRead();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Apps</title>
<link href="screen.css" rel="stylesheet" media="screen">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
<script src="pmssActions.js"></script>
<style>
.apps{font-size:14px;line-height:1.45;max-width:980px;margin:0 auto}
.apps h1{font-size:1.5rem;margin:4px 0 14px}
.apps h2{font-size:1.05rem;color:#fff;margin:24px 0 8px}
.group{color:#9fb0c3;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 4px}
.list{border:1px solid #2e3b4f;border-radius:8px;overflow:hidden}
.row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-top:1px solid #223042;background:#0f1b2b}
.row:first-child{border-top:0}.row .main{flex:1;min-width:0}
.row .name{font-weight:bold;color:#fff}.row .desc{color:#9fb0c3;font-size:13px}
.pill{font-size:12px;padding:2px 9px;border-radius:999px;white-space:nowrap}
.p-run{background:#12351f;color:#8fd18f}.p-stop{background:#3a1d1d;color:#f19999}.p-off{background:#1f2937;color:#9fb0c3}
.p-warn{background:#3a2e12;color:#f0b429}.acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.b{font-size:13px;padding:5px 12px;border-radius:6px;border:1px solid #2e3b4f;background:#13293d;color:#e6edf3;text-decoration:none;white-space:nowrap;cursor:pointer}
.b-pri{background:#0e7490;border-color:#0e7490;color:#fff}.b-warn{border-color:#6b5420;color:#f0b429;background:#2a2414}
.search{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:8px;border:1px solid #2e3b4f;background:#0b1220;color:#e6edf3;font-size:14px;margin:4px 0 10px}
.note{color:#9fb0c3;font-size:13px;margin:0 0 8px}.mrow{display:flex;gap:12px;align-items:baseline;padding:7px 14px;border-top:1px solid #223042;background:#0f1b2b}
.mrow:first-child{border-top:0}.mrow .name{font-weight:bold;color:#fff;min-width:170px}.mrow .desc{flex:1;color:#9fb0c3;font-size:13px}
.mrow .tag{font-size:11px;color:#f0b429;margin-left:6px}.mrow a{white-space:nowrap;font-size:13px}
#pmss-action-notice{display:none;position:fixed;top:10px;right:10px;z-index:9999;max-width:580px;padding:8px 12px;border:1px solid #2e3b4f;background:#13293d;color:#e6edf3;font-weight:bold}
#pmss-action-notice.pmss-error{border-color:#f19999;background:#3a1d1d;color:#f19999}.pmss-action-loading{margin-left:6px;color:#9fb0c3}
@media (max-width:640px){.row{flex-wrap:wrap}.acts{width:100%;justify-content:flex-start}.mrow{flex-wrap:wrap}.mrow .name{min-width:0}}
</style></head><body><div id="pmss-action-notice" role="status" aria-live="polite"></div>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body"><div class="apps">
<h1>Apps</h1><h2>Your apps</h2>
<div class="group">Media Stack</div><div class="list">
<?php if ($installedIds === array()): ?>
<div class="row"><div class="main"><div class="name">Media Stack is not installed</div><div class="desc"><?php
$names = array_column(pmssMediaStackPanelAppDefinitionsRead(), 'label');
$names[] = 'Cloudplow';
echo pmssAppsEscape(implode(', ', $names));
?></div><div class="desc"><?= pmssAppsEscape($mediaStackStatus['message'] ?? '') ?></div></div>
<div class="acts"><button type="button" class="b b-pri" onclick="pmssMediaStackStart(this);"<?= empty($mediaStackStatus['canStart']) ? ' disabled' : '' ?>>Install Media Stack</button></div></div>
<?php else: foreach (array_merge(pmssMediaStackPanelAppDefinitionsRead(), array('cloudplow' => array('label' => 'Cloudplow'))) as $id => $definition):
$name = (string) $definition['label'];
$state = pmssAppsRuntimeStateRead($runtime, $id);
$security = $mediaStackStatus['security'][$id] ?? array();
$url = is_array($security) ? (string) ($security['url'] ?? '') : '';
?>
<?= pmssAppsRowStart($name, $descriptions[$name] ?? '') ?>
<span class="pill <?= $state === 'running' ? 'p-run' : ($state === 'unknown' ? 'p-off' : 'p-stop') ?>"><?= pmssAppsEscape(ucfirst($state)) ?></span>
<?php if ($security !== array() && empty($security['protected'])): ?><span class="pill p-warn">No login set</span><?php endif; ?>
<div class="acts">
<?php if ($state === 'running' && $url !== ''): ?><a class="b b-pri" href="<?= pmssAppsEscape($url) ?>" target="_blank" rel="noopener">Open</a><?php endif; ?>
<?php if ($state === 'stopped' && !empty($mediaStackStatus['canRestart'])): ?><button type="button" class="b b-pri" title="Start stopped apps" onclick="pmssMediaStackStartStopped(this);">Start</button><?php endif; ?>
<?php if (!empty($security['canSecure'])): ?><button type="button" class="b b-warn" onclick="pmssMediaStackSecureApp(this, <?= pmssAppsEscape(json_encode($id)) ?>);">Secure this app</button><?php endif; ?>
</div></div>
<?php endforeach; endif; ?>
</div>
<div class="group">Torrent clients</div><div class="list">
<?= pmssAppsRowStart('rTorrent + ruTorrent', $descriptions['rTorrent + ruTorrent']) ?>
<span class="pill p-run">Always on</span><div class="acts"><a class="b b-pri" href="<?= pmssAppsEscape($urls['rutorrent']) ?>" target="_blank" rel="noopener">Open</a>
<?= pmssAppsActionButtonBuild('Restart', 'rtorrentRestart.php', 'rTorrent restart request sent, please allow up to 2 minutes for restart to happen.', false, 'Sending rTorrent restart request...') ?></div></div>
<?php foreach (array('qBittorrent', 'Deluge') as $name):
$definition = $managedApps[$name] ?? array();
if (!isset($definition['endpoint'], $definition['binaries'], $definition['enable']) || !pmssWelcomeServiceAvailable($definition['endpoint'], $definition['binaries'])) continue;
$on = file_exists($definition['enable']);
$endpoint = $definition['endpoint'];
?>
<?= pmssAppsRowStart($name, $descriptions[$name] ?? '') ?><span class="pill <?= $on ? 'p-run' : 'p-off' ?>"><?= $on ? 'On' : 'Off' ?></span><div class="acts">
<?php if ($on): ?><a class="b b-pri" href="<?= pmssAppsEscape($urls[$name]) ?>" target="_blank" rel="noopener">Open</a>
<?= pmssAppsActionButtonBuild('Restart', $endpoint.'?action=restart', $name.' restart requested.', false, 'Restarting '.$name.'...') ?>
<?= pmssAppsActionButtonBuild('Turn off', $endpoint.'?action=disable', $name.' disabled.', true, 'Disabling '.$name.'...') ?>
<?php else:
$startSuccess = $name === 'Deluge'
    ? 'Deluge starting. Accessible at /deluge-USERNAME/. Refresh GUI to see tab.'
    : 'qBittorrent starting, access at /user-USERNAME/qbittorrent/ — Refresh GUI to see tab.';
$startPending = $name === 'Deluge' ? 'Deluge start request sent...' : 'Starting qBittorrent...';
?><?= pmssAppsActionButtonBuild('Turn on', $endpoint.'?action=start', $startSuccess, true, $startPending, 'b-pri') ?><?php endif; ?>
</div></div>
<?php endforeach; ?></div>
<div class="group">Transfers</div><div class="list">
<?php $name = 'rclone'; $definition = $managedApps[$name] ?? array();
if (isset($definition['endpoint'], $definition['binaries'], $definition['enable']) && pmssWelcomeServiceAvailable($definition['endpoint'], $definition['binaries'])):
$on = file_exists($definition['enable']); $endpoint = $definition['endpoint']; ?>
<?= pmssAppsRowStart($name, $descriptions[$name] ?? '') ?><span class="pill <?= $on ? 'p-run' : 'p-off' ?>"><?= $on ? 'On' : 'Off' ?></span><div class="acts">
<?php if ($on): ?><a class="b b-pri" href="<?= pmssAppsEscape($urls[$name]) ?>" target="_blank" rel="noopener">Open</a>
<?= pmssAppsActionButtonBuild('Restart', $endpoint.'?action=restart', 'Rclone restart requested.', false, 'Restarting Rclone...') ?>
<?= pmssAppsActionButtonBuild('Turn off', $endpoint.'?action=disable', 'Rclone disabled.', true, 'Disabling Rclone...') ?>
<?php else: ?><?= pmssAppsActionButtonBuild('Turn on', $endpoint.'?action=start', 'Rclone starting, access at /user-USERNAME/rclone. Refresh GUI to see tab.', true, 'Starting Rclone...', 'b-pri') ?><?php endif; ?>
</div></div><?php endif; ?></div>
<h2>Find more apps</h2>
<p class="note">39 more apps you can set up yourself with rootless Docker. Each needs its own login turned on, because other accounts on this server can reach its port.</p>
<input id="pmss-apps-search" class="search" type="search" aria-label="Search apps" placeholder="Search apps, e.g. comics, photos, IRC">
<div class="list" id="pmss-more-apps">
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?>
<div class="mrow"><span class="name"><?= pmssAppsEscape($app[0]) ?><?php if ($app[3] !== ''): ?><span class="tag"><?= pmssAppsEscape($app[3]) ?></span><?php endif; ?></span><span class="desc"><?= pmssAppsEscape($app[2]) ?></span><a href="<?= pmssAppsEscape($urls[$category]) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div>
<?php endforeach; endforeach; ?>
</div></div></div><div class="full_bottom"></div></div></div>
<script>
// The status endpoint returns Welcome markup, so reload this page after a media action.
window.pmssAppsActionComplete = function () { window.setTimeout(function () { location.reload(true); }, 900); };
<?php if (!empty($mediaStackStatus['poll'])): ?>window.setTimeout(function () { location.reload(true); }, 4000);<?php endif; ?>
document.getElementById('pmss-apps-search').addEventListener('input', function () {
    var query = this.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#pmss-more-apps .mrow');
    for (var i = 0; i < rows.length; i++) {
        var name = rows[i].querySelector('.name').textContent.toLowerCase();
        var description = rows[i].querySelector('.desc').textContent.toLowerCase();
        rows[i].style.display = name.indexOf(query) !== -1 || description.indexOf(query) !== -1 ? '' : 'none';
    }
});
</script></body></html>
