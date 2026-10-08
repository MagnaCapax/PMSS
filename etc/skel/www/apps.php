<?php
/** Customer app controls and the closed, documentation-only Docker catalog. */
require_once __DIR__.'/scriptsInc.php';
if (isset($_GET['status'])) { pmssCustomerAppsStatusJsonEmit(); return; }
if (isset($_GET['log'])) { pmssCustomerAppsLogJsonEmit(); return; }
require_once __DIR__.'/userMediaStackPanel.php';

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
$status = pmssCustomerAppsStatusRead($home, $username, $hostname);
$urls = pmssAppsAllowedUrlsRead();
$lsioCatalog = is_file(__DIR__.'/appsLsioCatalog.php') ? require __DIR__.'/appsLsioCatalog.php' : array();
if (!is_array($lsioCatalog)) $lsioCatalog = array();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Apps</title>
<link href="screen.css" rel="stylesheet" media="screen">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script><script><?= pmssActionScriptJs() ?></script>
<?= pmssCustomerAppsCss() ?>
<style>
.apps{font-size:14px;line-height:1.45;max-width:980px;margin:0 auto}.apps h1{font-size:1.5rem;margin:4px 0 14px}.apps>h2{font-size:1.05rem;color:#fff;margin:24px 0 8px}
.apps>.list,.lsio-group .list{border:1px solid #2e3b4f;border-radius:8px;overflow:hidden}
.lsio-group .group{color:#9fb0c3;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 4px}
.search{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:8px;border:1px solid #2e3b4f;background:#0b1220;color:#e6edf3;font-size:14px;margin:4px 0 10px}.note{color:#9fb0c3;font-size:13px;margin:0 0 8px}
.mrow{display:flex;gap:12px;align-items:baseline;padding:7px 14px;border-top:1px solid #223042;background:#0f1b2b}.mrow:first-child{border-top:0}.mrow .name{font-weight:bold;color:#fff;min-width:170px}.mrow .desc{flex:1;color:#9fb0c3;font-size:13px}.mrow .tag{font-size:11px;color:#f0b429;margin-left:6px}.mrow a{white-space:nowrap;font-size:13px}
@media(max-width:640px){.mrow{flex-wrap:wrap}.mrow .name{min-width:0}}
</style></head><body><div id="pmss-action-notice" role="status" aria-live="polite"></div>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body"><div class="apps">
<h1>Apps</h1>
<?= pmssCustomerAppsSectionHtmlBuild($status, 'apps.php?status=1') ?>
<h2>Find more apps</h2><p class="note">You can set up more apps yourself with rootless Docker. LinuxServer.io examples publish ports on every address (for example 8080:8080). On this server that puts the app on the internet: publish it as 127.0.0.1:8080:8080 instead, and turn on the app's own login — other accounts on this server can reach a port on 127.0.0.1.</p>
<input id="pmss-apps-search" class="search" type="search" aria-label="Search apps" placeholder="Search apps, e.g. comics, photos, IRC">
<h2>Guides on the Pulsed Media wiki</h2><div class="list" id="pmss-more-apps">
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?><div class="mrow" data-category="<?= pmssCustomerHtmlAttr($category) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app[0]) ?><?php if ($app[3] !== ''): ?><span class="tag"><?= pmssCustomerHtmlAttr($app[3]) ?></span><?php endif; ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app[2]) ?></span><a href="<?= pmssCustomerHtmlAttr($urls[$category]) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div><?php endforeach; endforeach; ?></div>
<?php if ($lsioCatalog): ?><h2>More from LinuxServer.io (<?= count($lsioCatalog) ?>)</h2><div id="pmss-lsio-apps">
<?php $lastCategory = null; foreach ($lsioCatalog as $app): if ($app['category'] !== $lastCategory): if ($lastCategory !== null): ?></div></div><?php endif; $lastCategory = $app['category']; ?><div class="lsio-group"><div class="group"><?= pmssCustomerHtmlAttr(str_replace(',', ', ', $lastCategory)) ?></div><div class="list"><?php endif; ?>
<div class="mrow" data-category="<?= pmssCustomerHtmlAttr(str_replace(',', ', ', $app['category'])) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app['title']) ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app['description']) ?></span><a href="<?= pmssCustomerHtmlAttr($app['guide']) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div>
<?php endforeach; ?></div></div></div><?php endif; ?>
</div></div><div class="full_bottom"></div></div></div>
<script>
$('#pmss-apps-search').on('input', function() { var query = this.value.toLowerCase().trim(); $('#pmss-more-apps .mrow, #pmss-lsio-apps .mrow').each(function() { $(this).toggle(($(this).find('.name, .desc').text() + ' ' + $(this).attr('data-category')).toLowerCase().indexOf(query) !== -1); }); $('#pmss-lsio-apps .lsio-group').each(function() { $(this).toggle($(this).find('.mrow:visible').length > 0); }); });
</script></body></html>
