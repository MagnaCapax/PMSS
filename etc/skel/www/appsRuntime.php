<?php
/** Live, customer-owned Apps tab probes and bounded app logs. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/userMediaStackPanel.php';

/** The wiki catalog keeps the existing category and native-install metadata. */
function pmssAppsCatalogRead(): array
{
    $categories = array(
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
            array('JDownloader 2', true, 'Download manager for direct links and file hosts', ''),
            array('Seerr', true, 'Media requests for Jellyfin, Plex and Emby', ''),
            array('DroppedNeedle', false, 'Music requests that drive your own slskd or SABnzbd', ''),
            array('ReadMeABook', false, 'Audiobook requests: search, download, merge, import', ''),
        ),
        'Library automation' => array(
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
            array('Vaultwarden', false, 'Password server for the Bitwarden apps', ''),
        ),
        'Dashboards, chat and desktop' => array(
            array('Organizr', true, 'Puts your other apps behind one login as tabs', ''),
            array('The Lounge', true, 'Always-on IRC client in the browser', ''),
            array('Mattermost', true, 'Team chat with channels and file sharing', ''),
            array('Webtop', false, 'A full Linux desktop in a browser tab', ''),
        ),
    );
    foreach (require __DIR__.'/toggleAppCatalog.php' as $row) {
        array_splice($categories[$row['listingCategory']], $row['listingIndex'], 0,
            array(array($row['label'], $row['listingAvailable'], $row['listingDescription'], '')));
    }
    return $categories;
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

/** Guard every helper supplied by another customer file before the page runs. */
function pmssAppsRuntimeReady(): bool
{
    foreach (array('pmssActionScriptJs', 'pmssFrontendShellExec', 'pmssCustomerHomePath',
        'pmssCustomerNativePidsRead', 'pmssCustomerAppsStatusRead', 'pmssCustomerManagedAppDefinitions',
        'pmssWelcomeServiceAvailable', 'pmssMediaStackPanelAppDefinitionsRead',
        'pmssMediaStackPanelStatusRead', 'pmssMediaStackPanelExpectedAppIdsRead') as $helper) {
        if (!function_exists($helper)) return false;
    }
    return true;
}

/** Compatibility entry point for existing Apps callers. */
function pmssAppsLiveStatusRead(string $home, string $username, string $hostname): array
{
    return pmssCustomerAppsStatusRead($home, $username, $hostname);
}

/** Read only a fixed app log, cap work to 16 KiB and output to twenty short lines. */
function pmssAppsLogTailRead(string $home, string $app): string
{
    if (!in_array($app, pmssCustomerAppsMediaIdsRead(), true)) return '';
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
