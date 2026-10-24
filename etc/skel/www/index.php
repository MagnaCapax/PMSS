<?php
/**
 * PMSS: Master GUI, index frame loader.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010–2015.
 *
 * Responsibilities:
 *  - Fetch remote frame definitions from pulsedmedia.com when available.
 *  - Fallback to a local tab set when remote frames cannot be loaded.
 *  - Merge per-user custom tabs and enabled app tabs into the frame list.
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 */

$htmlHead = '';
$frames   = array();
$useLocalFrames = false;

if (file_exists(__DIR__.'/welcomeMessage.php')) require_once __DIR__.'/welcomeMessage.php';
if (is_file(__DIR__.'/pikkumiesPanel.php')) require_once __DIR__.'/pikkumiesPanel.php';

/** Detect frames that must open outside the iframe tab container. */
function pmssFrameOpensInNewWindow(array $frame)
{
    return isset($frame['target']) && $frame['target'] === '_blank';
}

/** Icon for a tab: known tab keys get their own, any other tab a generic window. Names are pmssPanelTabIconSymbols() ids. */
function pmssPanelTabIcon($key, $url)
{
    $icons = array('welcome' => 'house', 'pikkumies' => 'sparkles', 'openclaw' => 'bot', 'rutorrent' => 'download',
        'qbittorrent' => 'download', 'deluge' => 'download', 'transmission' => 'download', 'filemanager' => 'folder',
        'info' => 'gauge', 'stats' => 'chart-column', 'wiki' => 'book-open', 'console' => 'square-terminal',
        'pmssconfigure' => 'settings', 'apps' => 'layout-grid', 'jellyfin' => 'clapperboard', 'sonarr' => 'tv',
        'radarr' => 'film', 'prowlarr' => 'search', 'lidarr' => 'headphones', 'readarr' => 'book-open-text',
        'sabnzbd' => 'newspaper', 'autobrr' => 'zap', 'invidious' => 'circle-play', 'rclone' => 'cloud', 'owncloud' => 'cloud');
    $key = strtolower((string) $key);
    if (isset($icons[$key])) return $icons[$key];
    return strpos((string) $url, 'rutorrent') === 0 ? 'download' : 'app-window';
}

/** One tab label: on a tab with its own icon a leading pictograph such as Configure's gear is dropped (the icon replaces it);
    there an all-lower-case label is also capitalised. A custom tab keeps its label as written. */
function pmssPanelTabLabel($linkText, $ownIcon = true)
{
    $label = !$ownIcon ? trim((string) $linkText) : trim((string) preg_replace('/^[\p{So}\x{FE0F}\s]+/u', '', (string) $linkText));
    if ($label === '') $label = trim((string) $linkText);
    return $ownIcon && $label === strtolower($label) ? ucfirst($label) : $label;
}

/** The tab bar's icons, inlined once per page: Lucide 1.61.0 (ISC, Copyright (c) 2026 Lucide Icons and Contributors). */
function pmssPanelTabIconSymbols()
{
    return '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">'
        . '<symbol id="pmss-i-house" viewBox="0 0 24 24"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol><symbol id="pmss-i-sparkles" viewBox="0 0 24 24"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></symbol><symbol id="pmss-i-bot" viewBox="0 0 24 24"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></symbol><symbol id="pmss-i-download" viewBox="0 0 24 24"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></symbol><symbol id="pmss-i-folder" viewBox="0 0 24 24"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></symbol><symbol id="pmss-i-gauge" viewBox="0 0 24 24"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></symbol><symbol id="pmss-i-book-open" viewBox="0 0 24 24"><path d="M12 5v16"/><path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"/></symbol><symbol id="pmss-i-square-terminal" viewBox="0 0 24 24"><path d="m7 11 2-2-2-2"/><path d="M11 13h4"/><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/></symbol><symbol id="pmss-i-settings" viewBox="0 0 24 24"><path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/></symbol><symbol id="pmss-i-clapperboard" viewBox="0 0 24 24"><path d="m12.296 3.464 3.02 3.956"/><path d="M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3z"/><path d="M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="m6.18 5.276 3.1 3.899"/></symbol><symbol id="pmss-i-tv" viewBox="0 0 24 24"><path d="m17 2-5 5-5-5"/><rect width="20" height="15" x="2" y="7" rx="2"/></symbol><symbol id="pmss-i-film" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/></symbol><symbol id="pmss-i-search" viewBox="0 0 24 24"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></symbol><symbol id="pmss-i-headphones" viewBox="0 0 24 24"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></symbol><symbol id="pmss-i-book-open-text" viewBox="0 0 24 24"><path d="M12 5v16"/><path d="M16 13h2"/><path d="M16 9h2"/><path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"/><path d="M6 13h2"/><path d="M6 9h2"/></symbol><symbol id="pmss-i-newspaper" viewBox="0 0 24 24"><path d="M15 18h-5"/><path d="M18 14h-8"/><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-4 0v-9a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="10" y="6" rx="1"/></symbol><symbol id="pmss-i-zap" viewBox="0 0 24 24"><path d="M15.914 4a1.5 1.5 0 00-2.474-1.561l-9 9A1.5 1.5 0 005.5 14h4.002a.5.5 0 01.471.666L8.086 20a1.5 1.5 0 002.475 1.56l9-9A1.5 1.5 0 0018.5 10h-3.997a.5.5 0 01-.472-.667z"/></symbol><symbol id="pmss-i-circle-play" viewBox="0 0 24 24"><path d="M9 9.003a1 1 0 0 1 1.517-.859l4.997 2.997a1 1 0 0 1 0 1.718l-4.997 2.997A1 1 0 0 1 9 14.996z"/><circle cx="12" cy="12" r="10"/></symbol><symbol id="pmss-i-cloud" viewBox="0 0 24 24"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></symbol><symbol id="pmss-i-app-window" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 4v4"/><path d="M2 8h20"/><path d="M6 4v4"/></symbol><symbol id="pmss-i-layout-grid" viewBox="0 0 24 24"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></symbol><symbol id="pmss-i-chart-column" viewBox="0 0 24 24"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></symbol><symbol id="pmss-i-chevron-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol><symbol id="pmss-i-chevron-right" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol><symbol id="pmss-i-loader-circle" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></symbol>'
        . '</svg>';
}

/** An inline icon from pmssPanelTabIconSymbols(). */
function pmssPanelTabIconHtml($name)
{
    $ref = '#pmss-i-' . $name;
    return '<svg class="pt-ico" aria-hidden="true" focusable="false"><use href="' . $ref . '" xlink:href="' . $ref . '"></use></svg>';
}

/** Build one local tab frame record. */
function pmssLocalFrameDefinition($url, $linkText, $title)
{
    return array('url' => $url, 'linkText' => $linkText, 'title' => $title);
}

/** Parse a human-readable quota token into bytes. */
function pmssLocalFrameQuotaSizeToBytes($value)
{
    $value = trim(str_replace('*', '', $value));
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTPE]?)(?:i?B)?$/i', $value, $matches) !== 1) {
        return null;
    }

    $powerMap = array('' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6);
    $unit = strtoupper($matches[2]);
    if (!array_key_exists($unit, $powerMap)) {
        return null;
    }

    return (int) round((float) $matches[1] * pow(1024, $powerMap[$unit]));
}

/**
 * Build the quota payload expected by welcome.php from the local snapshot.
 *
 * @return array<string,int|bool>
 */
function pmssLocalFrameQuotaInfoBuild($used, $softLimit, $hardLimit)
{
    $usedBytes = pmssLocalFrameQuotaSizeToBytes($used);
    $totalSpace = pmssLocalFrameQuotaSizeToBytes($softLimit);
    $hardLimitBytes = pmssLocalFrameQuotaSizeToBytes($hardLimit);
    if ($usedBytes === null || $totalSpace === null || $hardLimitBytes === null) {
        return array();
    }

    return array(
        'overQuota'  => $hardLimitBytes > 0 && $usedBytes > $hardLimitBytes,
        'totalSpace' => $totalSpace,
        'freeSpace'  => $totalSpace - $usedBytes,
        'hardLimit'  => $hardLimitBytes,
        'usedBytes'  => $usedBytes,
    );
}

/**
 * Read ~/.quota output as written by updateQuotas.php.
 *
 * @return array<string,int|bool>
 */
function pmssLocalFrameQuotaInfoRead($quotaPath = '../.quota')
{
    $raw = @file_get_contents($quotaPath);
    if (!is_string($raw) || trim($raw) === '') {
        return array();
    }

    $expectWrappedValues = false;
    foreach (preg_split('/\r?\n/', trim($raw)) ?: array() as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^\/dev\/\S+\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $matches) === 1) {
            return pmssLocalFrameQuotaInfoBuild($matches[1], $matches[2], $matches[3]);
        }

        if ($expectWrappedValues && preg_match('/^(\S+)\s+(\S+)\s+(\S+)/', $line, $matches) === 1) {
            return pmssLocalFrameQuotaInfoBuild($matches[1], $matches[2], $matches[3]);
        }

        $expectWrappedValues = preg_match('/^\/dev\/\S+$/', $line) === 1;
    }

    return array();
}

/** Build the welcome iframe URL with local quota data when available. */
function pmssLocalFrameWelcomeUrlBuild($quotaPath = '../.quota')
{
    $quotaInfo = pmssLocalFrameQuotaInfoRead($quotaPath);
    if (count($quotaInfo) == 0) {
        return 'welcome.php';
    }

    return 'welcome.php?quota='.urlencode(serialize($quotaInfo));
}

/** Infer the tenant username from the customer home directory. */
function pmssLocalFrameCurrentUserRead($homePath = '..')
{
    $home = realpath($homePath);
    if (!is_string($home) || $home === '') {
        return '';
    }

    $username = basename($home);
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $username) === 1 ? $username : '';
}

/**
 * Return static tab metadata for app proxy fragments the customer tree can see.
 *
 * @return array<string,array<string,string>>
 */
function pmssLocalFrameProxyAppDefinitions($username = '')
{
    $publicBase = $username !== '' ? '/public-'.$username.'/' : '';
    return array(
        'qbittorrent' => pmssLocalFrameDefinition('qbittorrent/', 'qBittorrent', 'qBittorrent - Torrent web UI'),
        'deluge' => pmssLocalFrameDefinition('deluge/', 'Deluge', 'Deluge - Torrent web UI'),
        'rclone' => pmssLocalFrameDefinition('rclone/', 'Rclone', 'Rclone Web UI'),
        'openclaw' => pmssLocalFrameDefinition('configure.php#apps', 'OpenClaw', 'OpenClaw - AI agent'),
        'jellyfin' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'jellyfin/web/', 'Jellyfin', 'Jellyfin - Media server'),
        'radarr' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'radarr/', 'Radarr', 'Radarr - Movie manager'),
        'sonarr' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'sonarr/', 'Sonarr', 'Sonarr - TV manager'),
        'prowlarr' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'prowlarr/', 'Prowlarr', 'Prowlarr - Indexer manager'),
        'lidarr' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'lidarr/', 'Lidarr', 'Lidarr - Music manager'),
        'readarr' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'readarr/', 'Readarr', 'Readarr - Book manager'),
        'sabnzbd' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'sabnzbd/', 'SABnzbd', 'SABnzbd - Usenet downloader'),
        'autobrr' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'autobrr/', 'Autobrr', 'Autobrr - Release automation'),
        'invidious' => pmssLocalFrameDefinition('apps/invidious/', 'Invidious', 'Invidious - Video frontend'),
    );
}

/** Return the per-user enable flag for toggle-gated app tabs. */
function pmssLocalFrameProxyAppEnableFile($app)
{
    $toggleFiles = array(
        'qbittorrent' => '.qbittorrentEnable',
        'deluge'      => '.delugeEnable',
        'rclone'      => '.rcloneEnable',
    );

    return isset($toggleFiles[$app]) ? $toggleFiles[$app] : '';
}

/** Return true when a lighttpd proxy fragment exposes the named app path. */
function pmssLocalFrameProxyFragmentMentionsApp($fragment, $app)
{
    $appPattern = preg_quote($app, '/');
    $pathPrefix = '(?:user-[^"\']+\/(?:apps\/)?|public-[^"\']+\/)?';
    return preg_match('/\^\/'.$pathPrefix.$appPattern.'(?:\(|\/|\$)/i', $fragment) === 1
        || preg_match('/["\']\/'.$pathPrefix.$appPattern.'(?:\/|["\'])/i', $fragment) === 1;
}

/** Return true when an app proxy should surface as an enabled tab. */
function pmssLocalFrameProxyAppEnabled($app, $homePath = '..')
{
    $toggleFile = pmssLocalFrameProxyAppEnableFile($app);
    if ($toggleFile === '') {
        return true;
    }

    return is_file(rtrim($homePath, '/').'/'.$toggleFile);
}

/**
 * Discover locally proxied app tabs from customer-readable lighttpd fragments.
 *
 * @return array<string,array<string,string>>
 */
function pmssLocalFrameProxyAppFramesRead($customDir = '../.lighttpd/custom.d', $homePath = '..')
{
    $frames = array();
    if (!is_dir($customDir)) {
        return $frames;
    }

    $files = glob(rtrim($customDir, '/').'/*.conf');
    if (!is_array($files)) {
        return $frames;
    }
    sort($files);

    $definitions = pmssLocalFrameProxyAppDefinitions(pmssLocalFrameCurrentUserRead($homePath));
    foreach ($files as $file) {
        if (!is_readable($file) || is_dir($file)) {
            continue;
        }

        $fragment = @file_get_contents($file);
        if (!is_string($fragment) || $fragment === '') {
            continue;
        }

        foreach ($definitions as $app => $frame) {
            if (isset($frames[$app]) || $frame['url'] === '') {
                continue;
            }
            if (!pmssLocalFrameProxyAppEnabled($app, $homePath)) {
                continue;
            }

            if (pmssLocalFrameProxyFragmentMentionsApp($fragment, $app)) {
                $frames[$app] = $frame;
            }
        }
    }

    return $frames;
}

/**
 * Discover app tabs from customer-owned config directories when proxy fragments
 * have not been regenerated yet.
 *
 * @return array<string,array<string,string>>
 */
function pmssLocalFrameInstalledAppFramesRead($homePath = '..')
{
    $frames = array();
    $definitions = pmssLocalFrameProxyAppDefinitions(pmssLocalFrameCurrentUserRead($homePath));
    // A tab is shown only when the per-user enable flag is set — the SAME
    // signal the service watchdog uses to decide whether the backend runs.
    // A leftover config/port file from a disabled service must NOT surface a
    // tab: clicking it loads a backend that is not running and the customer
    // gets a raw 503. Per ADR 0021 #2: enabled features only.
    $signals = array(
        'qbittorrent' => array('signal' => '.config/qBittorrent', 'type' => 'dir'),
        'deluge'      => array('signal' => '.config/deluge',      'type' => 'dir'),
        'rclone'      => array('signal' => '.rclonePort',         'type' => 'file'),
    );

    $home = rtrim($homePath, '/');
    foreach ($signals as $app => $paths) {
        if (!isset($definitions[$app]) || $definitions[$app]['url'] === '') {
            continue;
        }
        $signalPath = $home.'/'.$paths['signal'];
        $signalPresent = $paths['type'] === 'file' ? is_file($signalPath) : is_dir($signalPath);
        if ($signalPresent && pmssLocalFrameProxyAppEnabled($app, $homePath)) {
            $frames[$app] = $definitions[$app];
        }
    }

    return $frames;
}

/**
 * Return the browser console tab when its backend is deployed (GH #777, ADR 0031):
 * the per-user launcher and the ttyd binary must both exist (ADR 0021 #2, same
 * gate as the info tab). The tab opens the ephemeral console; the persistent
 * (tmux) session stays an opt-in on the info tab. PMSS_CONSOLE_TTYD_BIN only
 * overrides the presence check (tests); console.php always runs /usr/bin/ttyd.
 *
 * @return array<string,array<string,string>>
 */
function pmssLocalFrameConsoleFrameRead($launcherPath = 'console.php')
{
    $ttyd = getenv('PMSS_CONSOLE_TTYD_BIN');
    $ttyd = (is_string($ttyd) && $ttyd !== '') ? $ttyd : '/usr/bin/ttyd';
    if (!is_file($launcherPath) || !is_file($ttyd)) {
        return array();
    }
    return array('console' => pmssLocalFrameDefinition('console.php', 'Console', 'Shell console in your browser'));
}

/**
 * Return the Apps tab only when its customer page has been delivered.
 *
 * @return array<string,array<string,string>>
 */
function pmssLocalFrameAppsFrameRead($path = 'apps.php')
{
    return pmssLocalFrameConfigureFrameRead() === array() && is_file($path)
        ? array('apps' => pmssLocalFrameDefinition('apps.php', 'Apps', 'Applications you can run'))
        : array();
}

/**
 * Show Configure only after both update-delivered customer files arrive.
 * #TODO(2026-11-01): Remove this transition check (ADR 0103 removal plan).
 */
function pmssLocalFrameConfigureFrameRead($path = 'configure.php', $helper = 'configureSettings.php')
{
    return is_file($path) && is_file($helper)
        ? array('pmssconfigure' => pmssLocalFrameDefinition('configure.php', '⚙ Configure', 'Apps and settings for your account'))
        : array();
}

/**
 * Read customer-owned custom tabs.
 *
 * Each non-comment line defines: appname|tooltip|label|url.
 *
 * @return array<string,array<string,string>>
 */
function pmssLocalFrameCustomFramesRead($path = '../.customFrames')
{
    $frameData = array();
    if (!file_exists($path)) {
        return $frameData;
    }

    $file = new SplFileObject($path);
    while (!$file->eof()) {
        $line = trim($file->fgets());
        if ($line === '' || strpos($line, "#") === 0) continue;
        $frameArray = explode("|", $line);
        if (count($frameArray) < 4 || $frameArray[3] === '') continue;
        $frameData[$frameArray[0]] = pmssLocalFrameDefinition($frameArray[3], $frameArray[2], $frameArray[1]);
    }

    return $frameData;
}

/**
 * Tab priorities from ~/.customFrames (ADR 0148): an optional fifth field, a whole number from -99 to 99.
 * A line with a priority and no URL only moves an existing tab: `openclaw||||50`. Higher sits further left.
 *
 * @return array<string,int> lower-case tab key => priority
 */
function pmssLocalFramePrioritiesRead($path = '../.customFrames')
{
    $priorities = array();
    if (!is_file($path) || is_link($path) || filesize($path) > 4096) return $priorities;
    foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $fields = explode('|', $line);
        if (count($fields) >= 5 && $fields[0] !== '' && preg_match('/^-?\d{1,2}$/D', trim($fields[4])) === 1) {
            $priorities[strtolower($fields[0])] = (int) trim($fields[4]);
        }
    }
    return $priorities;
}

/**
 * Order the tabs after Welcome by priority, highest first, keeping today's order among equals. Welcome stays first,
 * so the panel still opens on it (ADR 0021 #3). Defaults: Pikkumies 40 (right after Welcome, as before), OpenClaw 50
 * on an account whose rTorrent is off, the OpenClaw-first product (operator 2026-10-22). The account's own lines win.
 *
 * @param array<string,array<string,string>> $frames
 * @param array<string,int> $priorities
 * @return array<string,array<string,string>>
 */
function pmssLocalFramePriorityOrder(array $frames, array $priorities, $openClawFirst)
{
    $defaults = array('pikkumies' => 40);
    if ($openClawFirst) $defaults['openclaw'] = 50;
    $rows = array();
    $position = 0;
    foreach ($frames as $key => $frame) {
        $lower = strtolower((string) $key);
        $priority = $lower === 'welcome' ? 1000 : (isset($priorities[$lower]) ? $priorities[$lower] : (isset($defaults[$lower]) ? $defaults[$lower] : 0));
        $rows[] = array($priority, $position++, $key, $frame);
    }
    usort($rows, function ($a, $b) { return $a[0] === $b[0] ? $a[1] - $b[1] : $b[0] - $a[0]; });
    $ordered = array();
    foreach ($rows as $row) $ordered[$row[2]] = $row[3];
    return $ordered;
}

/** Drop the ruTorrent tab while rTorrent is switched off: a disabled feature has no tab (ADR 0021 #2). */
function pmssLocalFrameDisabledRtorrentDrop(array $frames, $disabled)
{
    if (!$disabled) return $frames;
    foreach ($frames as $key => $frame) {
        $url = isset($frame['url']) ? (string) $frame['url'] : '';
        if (strtolower((string) $key) === 'rutorrent' || preg_match('#(^|/)rutorrent/?$#i', $url) === 1) unset($frames[$key]);
    }
    return $frames;
}

/** Return whether this customer selected bundled frame definitions only. */
function pmssGuiFramesLocalOnlyEnabled($markerPath = '../.guiFramesLocalOnly')
{
    return is_file($markerPath) && !is_link($markerPath);
}

/** Apply the customer frame-source preference through one guarded marker. */
function pmssGuiFramesLocalOnlyPreferenceApply($mode, $markerPath = '../.guiFramesLocalOnly')
{
    if (!is_string($mode) || is_link($markerPath)) {
        return false;
    }

    if ($mode === 'remote') {
        return !file_exists($markerPath) || (is_file($markerPath) && @unlink($markerPath));
    }
    if ($mode !== 'local' || file_exists($markerPath)) {
        return $mode === 'local' && is_file($markerPath);
    }

    $marker = @fopen($markerPath, 'x');
    if (!is_resource($marker)) {
        return false;
    }
    fclose($marker);
    @chmod($markerPath, 0600);
    return pmssGuiFramesLocalOnlyEnabled($markerPath);
}

/** Require the same-origin AJAX request shape used by panel mutations. */
function pmssGuiFramesPreferenceRequestAllowed($server)
{
    return isset($server['REQUEST_METHOD'], $server['HTTP_X_REQUESTED_WITH'])
        && $server['REQUEST_METHOD'] === 'POST'
        && strcasecmp((string) $server['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;
}

/** Return whether this customer opted into proactive usage-alert email. */
function pmssUsageAlertsEnabled($markerPath = '../.usageAlertsEnabled')
{
    $permissions = @fileperms($markerPath);
    return is_file($markerPath) && !is_link($markerPath)
        && is_int($permissions) && ($permissions & 0777) === 0600;
}

/** Apply the customer usage-alert preference through one guarded marker. */
function pmssUsageAlertsPreferenceApply($mode, $markerPath = '../.usageAlertsEnabled')
{
    if (!is_string($mode) || is_link($markerPath)) {
        return false;
    }
    if ($mode === 'disabled') {
        return !file_exists($markerPath) || (is_file($markerPath) && @unlink($markerPath));
    }
    if ($mode !== 'enabled') {
        return false;
    }
    if (file_exists($markerPath)) {
        @chmod($markerPath, 0600);
        clearstatcache(true, $markerPath);
        return pmssUsageAlertsEnabled($markerPath);
    }

    $marker = @fopen($markerPath, 'x');
    if (!is_resource($marker)) {
        return false;
    }
    $written = fwrite($marker, "enabled\n") !== false;
    fclose($marker);
    if (!$written || !@chmod($markerPath, 0600)) {
        @unlink($markerPath);
        return false;
    }
    clearstatcache(true, $markerPath);
    return pmssUsageAlertsEnabled($markerPath);
}

if (isset($_POST['guiFramesMode'])) {
    if (!pmssGuiFramesPreferenceRequestAllowed($_SERVER)) {
        http_response_code(403);
        exit;
    }
    if (!pmssGuiFramesLocalOnlyPreferenceApply($_POST['guiFramesMode'])) {
        http_response_code(409);
        exit;
    }
    http_response_code(204);
    exit;
}

if (isset($_POST['usageAlertsMode'])) {
    if (!pmssGuiFramesPreferenceRequestAllowed($_SERVER)) {
        http_response_code(403);
        exit;
    }
    if (!pmssUsageAlertsPreferenceApply($_POST['usageAlertsMode'])) {
        http_response_code(409);
        exit;
    }
    http_response_code(204);
    exit;
}

// Remote frames can be disabled explicitly for debugging or fully offline
// deployments by exporting PMSS_DISABLE_REMOTE_FRAMES=1. Local frames are the
// FAILOVER, not a replacement: the remote guiFrames path is the primary on-load
// GUI auto-update mechanism. Reverted #601 per operator directive 2026-06-03 —
// removing the primary instead of keeping local as failover was the defect.
if (!getenv('PMSS_DISABLE_REMOTE_FRAMES') && !pmssGuiFramesLocalOnlyEnabled()) {
    $framesUrl = 'https://pulsedmedia.com/remote/guiFrames.php?v=2';
    $remoteFrames = function_exists('pmssWelcomeHttpContextCreate')
        ? @file_get_contents($framesUrl, false, pmssWelcomeHttpContextCreate())
        : false;
    if ($remoteFrames !== false && $remoteFrames !== '') {
        $decoded = @base64_decode($remoteFrames, true);
        if ($decoded !== false) {
            $framesCode = @unserialize($decoded);
            if (is_string($framesCode) && $framesCode !== '') {
                $frames = eval($framesCode);
                if (!is_array($frames)) {
                    $frames = array();
                    $useLocalFrames = true;
                }
            } else {
                $useLocalFrames = true;
            }
        } else {
            $useLocalFrames = true;
        }
    } else {
        $useLocalFrames = true;
    }
} else {
    $useLocalFrames = true;
}

// On-load heal (remote guiFrames self-updater) may have overwritten guiv files
// THIS request — including index.php itself. The executing index.php is the
// pre-heal copy, so its wrapper renders stale until the next load. When the heal
// reports it applied a fresh file ($pmssHealReload, set inside the eval'd payload),
// redirect ONCE so the freshly-written wrapper renders now instead of one load later.
// The pmssr=1 one-shot guard bounds this to a SINGLE redirect — never a loop — even
// if a healed file fails to converge (e.g. a 0-byte write). The headers_sent() guard
// degrades safely to the prior one-load-lag behaviour if output already started.
if (!empty($pmssHealReload) && !headers_sent()
        && strpos($_SERVER['REQUEST_URI'] ?? '', 'pmssr=') === false) {
    $pmssReloadUri  = $_SERVER['REQUEST_URI'];
    $pmssReloadUri .= (strpos($pmssReloadUri, '?') !== false) ? '&pmssr=1' : '?pmssr=1';
    header('Location: ' . $pmssReloadUri, true, 302);
    exit;
}

if ($useLocalFrames) {
    // Minimal local tab set used when remote frames are unavailable.
    // This keeps the familiar tabbed GUI layout even when pulsedmedia.com
    // is unreachable or remote frames are explicitly disabled.
    $htmlHead = <<<EOF
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="pmssTabs.js"></script>
        <link rel="stylesheet" href="jquery.tabs.css" type="text/css" media="print, projection, screen">
EOF;

    $frames = array(
        'welcome' => pmssLocalFrameDefinition(pmssLocalFrameWelcomeUrlBuild(), 'welcome', 'Welcome to your seedbox. Basic information'),
        'rutorrent' => pmssLocalFrameDefinition('rutorrent/', 'ruTorrent', 'ruTorrent - Torrent web UI'),
        'filemanager' => pmssLocalFrameDefinition((file_exists('filemanager.php') ? 'filemanager.php' : 'ajax/'), 'File manager', 'Manage your files'),
        'info' => pmssLocalFrameDefinition('info.php', 'info', 'Information, quota, server RAM'),
        // Wiki is an in-page iframe tab, not a new window. wiki.pulsedmedia.com
        // sends `CSP: frame-ancestors 'self' https://*.pulsedmedia.com`, so it
        // frames cleanly from any per-user seedbox subdomain. (Reverts the
        // b4d284f8 `target=_blank` workaround for the old SAMEORIGIN block,
        // which no longer exists. Per ADR 0021: top frame = in-page tabs only.)
        'wiki' => pmssLocalFrameDefinition('https://wiki.pulsedmedia.com', 'wiki', 'Pulsed Media Wiki'),
    );
}

// Own the browser title on both frame paths, with the short hostname first.
$panelHostname = function_exists('gethostname') ? (string) gethostname() : '';
$panelHostname = explode('.', $panelHostname !== '' ? $panelHostname : (string) php_uname('n'), 2)[0];
$panelVendor = function_exists('pmssCustomerSerializedArrayFileRead')
    ? pmssCustomerSerializedArrayFileRead('/etc/seedbox/config/vendor', 4096) : null;
$panelVendorName = isset($panelVendor['name']) && is_string($panelVendor['name']) && trim($panelVendor['name']) !== ''
    ? trim($panelVendor['name']) : 'Pulsed Media';
$panelTitle = trim($panelHostname.' '.$panelVendorName.' Dashboard');
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>var u = new URL(location.href); if (u.username || u.password) { u.username = ''; u.password = ''; location.replace(u.href); }</script>
<title><?=htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8');?></title>
<?=preg_replace('/<title\b[^>]*>.*?<\/title\s*>/is', '', $htmlHead);?>
<style>
html, body {
 margin: 0;
 padding: 0;
 height: 100%;
 width: 100%;
}

#content {
 width: 100%;
 top: 34px;
 height: 350px; 
 position: absolute;
 overflow: auto;
 border-top: 1px solid #97A5B0;
 overflow: hidden;
}

/* Tab bar. #tabs-prefixed so these win over any jquery.tabs.css copy, old or new, and over the remote head.
   The bar is exactly as tall as #content's top offset; setHeights() reads the rendered height. */
#tabs .tabs-nav {
    display: flex;
    position: relative;
    box-sizing: border-box;
    height: 34px;
    margin: 0;
    padding: 0 4px;
    list-style: none;
    background: #0d131b;
    border-bottom: 1px solid #243143;
    overflow-x: auto;
    overflow-y: hidden;
    white-space: nowrap;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    font: 500 13px/1 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
}
#tabs .tabs-nav::-webkit-scrollbar {display: none;}
#tabs .tabs-nav:after {content: none;}
#tabs .tabs-nav li {display: flex; flex: none; float: none; margin: 0; min-width: 0;}
#tabs .tabs-nav li.pt-end {margin-left: auto;}
#tabs .tabs-nav a {
    display: flex;
    align-items: center;
    position: initial;
    box-sizing: border-box;
    width: auto;
    height: 100%;
    min-width: 0;
    min-height: 0;
    padding: 0 11px;
    color: #9aa9bc;
    font-size: 13px;
    font-weight: 500;
    background: none;
    background-image: none;
    border: 0;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    text-decoration: none;
    cursor: pointer;
}
#tabs .tabs-nav a span {display: inline; width: auto; height: auto; min-width: 0; min-height: 0; padding: 0; background: none; border-radius: 0;
    font: inherit; color: inherit;}
#tabs .tabs-nav a:hover {color: #e6edf3; background: #141c27; text-decoration: none;}
#tabs .tabs-nav a[aria-selected="true"], #tabs .tabs-nav .tabs-selected a {
    color: #e6edf3; background: #1a2433; border-bottom-color: #4fc3f7; font-weight: 600; cursor: default;
}
#tabs .tabs-nav a:focus {outline: 2px solid #9dd8f5; outline-offset: -2px;}
#tabs .tabs-nav a:focus:not(:focus-visible) {outline: none;}
#tabs .pt-ico {flex: none; width: 16px; height: 16px; margin-right: 7px; fill: none; stroke: currentColor;
    stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;}
#tabs a[aria-selected="true"] .pt-ico, #tabs .tabs-selected .pt-ico {color: #4fc3f7;}
#tabs .tabs-nav a .pt-mark {flex: none; box-sizing: border-box; width: 8px; height: 8px; margin-left: 6px; border: 2px solid #d29922; border-radius: 50%; background: none;}
#tabs .tabs-nav a .pt-mark.pt-bad {border-color: #f85149; background: #f85149;}
#tabs .tabs-nav a .pt-mark[hidden], #tabs .pt-scroll[hidden], #content .pt-note[hidden] {display: none;}
#tabs .pt-sr {position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;}
#tabs .pt-loading .pt-ico {animation: pmss-spin .9s linear infinite;}
#tabs .pt-scroll {position: absolute; top: 0; z-index: 3; width: 34px; height: 33px; padding: 0; border: 0; cursor: pointer;
    color: #e6edf3; font-size: 0; background: #0d131b;}
#tabs .pt-scroll .pt-ico {margin: 0;}
#tabs .pt-prev {left: 0; box-shadow: 10px 0 10px -4px #0d131b;}
#tabs .pt-next {right: 0; box-shadow: -10px 0 10px -4px #0d131b;}
#tabs .pt-scroll:hover {background: #141c27;}
#tabs .pt-scroll:focus {outline: 2px solid #9dd8f5; outline-offset: -2px;}
#content .pt-note {position: absolute; top: 8px; right: 12px; z-index: 5; max-width: 70%; padding: 7px 10px; border: 1px solid #243143; border-radius: 8px;
    background: #1a2433; color: #e6edf3; font: 13px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;}
#content .pt-note a {color: #9dd8f5;}
#content:before {content: ""; position: absolute; top: 0; left: 0; z-index: 4; width: 0; height: 2px; background: #4fc3f7; opacity: 0;}
#content.pt-busy:before {opacity: 1; animation: pmss-progress 8s cubic-bezier(.1,.7,.3,1) forwards;}
@keyframes pmss-spin {to {transform: rotate(360deg);}}
@keyframes pmss-progress {from {width: 0;} to {width: 90%;}}
@media (prefers-reduced-motion: reduce) {
    #tabs .pt-loading .pt-ico, #content.pt-busy:before {animation: none;}
    #content.pt-busy:before {width: 40%;}
}
@media (pointer: coarse) {
    #tabs .tabs-nav {height: 44px;}
    #tabs .pt-scroll {width: 40px; height: 43px;}
    #content {top: 44px;}
}
h6{color: #26c2ff; }
hr {background-color: #4b4b4b;}
#content {border: none;}
#pikkumies.tabs-container {padding:0;background:#0f1720;color-scheme:dark;}
.pmss-pikkumies-header {height:28px;display:flex;align-items:center;gap:12px;padding:0 12px;
    background:#0f1720;color:#cbd5e1;font:12px Arial,sans-serif;white-space:nowrap;overflow-x:auto;}
.pmss-pikkumies-header a {color:#9dd8f5;}
.pmss-pikkumies-header a:focus-visible {outline:2px solid #9dd8f5;outline-offset:2px;}
#pikkumiesFrame {display:block;background:#0f1720;color-scheme:dark;height:calc(100% - 28px);}

/* Tab container + content-frame styling, inlined so the panel renders correctly even
   when jquery.tabs.css is not yet present (the on-load heal delivers PHP only; without
   #tabs's background the container renders white = the thin white bar above the frames).
   jquery.tabs.css keeps its own copy; this is the self-contained fallback. */
#tabs {
    background: black;
}
.tabs-container {
    border-top: 1px solid #4f4f4f;
    padding: 1em 8px;
    background: #0f1720;
    color-scheme: dark;
}

.full_body {
    background: none;
    border: none;
    margin-top: 15px;
}

<?php
$frameData = pmssLocalFrameCustomFramesRead();
// Case-insensitive set of keys already present in the primary frame set ($frames, from the
// remote guiFrames master or the local failover). The remote master keys some app tabs with
// mixed case (e.g. 'qBittorrent') while the local installed/proxy readers key them lower-case
// ('qbittorrent'); an exact-key isset() guard misses that case difference and renders the tab
// TWICE. Dedup case-insensitively so a tab the master already provides is never re-added.
$pmssFramesKeysLower = array();
foreach (array_keys($frames) as $pmssFrameKey) { $pmssFramesKeysLower[strtolower($pmssFrameKey)] = true; }
foreach (array(pmssLocalFrameInstalledAppFramesRead(), pmssLocalFrameProxyAppFramesRead(), pmssLocalFrameConsoleFrameRead(), pmssLocalFrameAppsFrameRead(), pmssLocalFrameConfigureFrameRead()) as $pmssCandidateFrames) {
    foreach ($pmssCandidateFrames as $app => $frame) {
        if (!isset($pmssFramesKeysLower[strtolower($app)]) && !isset($frameData[$app])) {
            $frameData[$app] = $frame;
        }
    }
}
if (file_exists('../.delugeEnable') && file_exists('deluge.php') && !isset($pmssFramesKeysLower['deluge']) && !isset($frameData['deluge'])) {
    $frameData['deluge'] = pmssLocalFrameDefinition('deluge/', 'Deluge', 'Deluge - Torrent web UI');
}
$frames = array_merge($frames, $frameData);
$pmssPikkumiesState = array('visible' => false, 'origin' => '', 'mode' => 'simplified');
if (function_exists('pmssPikkumiesPanelStateRead') && is_file(__DIR__.'/pikkumiesOpen.php')) {
    list($pmssPikkumiesHome, $pmssPikkumiesName) = pmssPikkumiesPanelAccount();
    $pmssPikkumiesState = pmssPikkumiesPanelStateRead($pmssPikkumiesHome, $pmssPikkumiesName);
}
// Local feature frames always follow Welcome, regardless of remote frame order.
unset($frames['pikkumies']);
if ($pmssPikkumiesState['visible'] && isset($frames['welcome'])) {
    $pmssWelcomePosition = array_search('welcome', array_keys($frames), true) + 1;
    $frames = array_slice($frames, 0, $pmssWelcomePosition, true)
        + array('pikkumies' => pmssLocalFrameDefinition('pikkumiesOpen.php?session=pm1', 'Pikkumies', 'Ask Pikkumies, your server helper'))
        + array_slice($frames, $pmssWelcomePosition, null, true);
}
$pmssRtorrentDisabled = file_exists('../.rtorrentDisable');
$frames = pmssLocalFrameDisabledRtorrentDrop($frames, $pmssRtorrentDisabled);
$frames = pmssLocalFramePriorityOrder($frames, pmssLocalFramePrioritiesRead(), $pmssRtorrentDisabled);
// Remote and customer frame definitions cannot move or duplicate Configure.
$pmssConfigureFrame = pmssLocalFrameConfigureFrameRead();
foreach ($frames as $pmssFrameKey => $pmssFrame) {
    if ($pmssFrameKey === 'pmssconfigure' || (isset($pmssFrame['url']) && $pmssFrame['url'] === 'configure.php')
        || ($pmssConfigureFrame !== array() && ($pmssFrameKey === 'apps' || (isset($pmssFrame['url']) && $pmssFrame['url'] === 'apps.php')))) unset($frames[$pmssFrameKey]);
}
if ($pmssConfigureFrame !== array()) {
    $frames = array_merge($frames, $pmssConfigureFrame);
}

$styleList = array('iframe');
foreach($frames AS $thisId => $thisFrame) {
    if (!pmssFrameOpensInNewWindow($thisFrame)) {
        $styleList[] = '#' . $thisId;
    }
}
$styleList = implode(', ', $styleList);
echo $styleList . '{';
?>
 padding: 0;
 border: 0;
 margin: 0;
}
</style>
</head>
<body>

<div id="tabs">
<?=pmssPanelTabIconSymbols();?>
        <ul class="tabs-nav" role="tablist" aria-label="Panel sections">
<?php
$pmssTabFirst = true;
foreach($frames AS $thisId => $thisFrame) {
    // Frame fields can come from the customer-owned .customFrames file;
    // escape for the HTML and JS-in-attribute contexts below.
    $frameTitleHtml = htmlspecialchars((string) ($thisFrame['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $frameIcon      = pmssPanelTabIcon($thisId, $thisFrame['url'] ?? '');
    $frameTextHtml  = htmlspecialchars(pmssPanelTabLabel($thisFrame['linkText'] ?? '', $frameIcon !== 'app-window'), ENT_QUOTES, 'UTF-8');
    $frameIconHtml  = pmssPanelTabIconHtml($frameIcon);
    $frameUrlHtml   = htmlspecialchars((string) ($thisFrame['url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $frameIdHtml    = htmlspecialchars((string) $thisId, ENT_QUOTES, 'UTF-8');
    // JS string context: escape backslashes/quotes first, then HTML-escape
    // for the surrounding attribute.
    $frameIdJs  = htmlspecialchars(str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $thisId), ENT_QUOTES, 'UTF-8');
    $frameUrlJs = htmlspecialchars(str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) ($thisFrame['url'] ?? '')), ENT_QUOTES, 'UTF-8');

    if (pmssFrameOpensInNewWindow($thisFrame)) {
        echo "\t\t" . '<li><a href="' . $frameUrlHtml . '" title="' . $frameTitleHtml . '" target="_blank" rel="noopener noreferrer">' . $frameIconHtml . '<span>' .
            $frameTextHtml . '</span></a></li>' . "\n";
        continue;
    }

    // role=tab with roving tabindex: only the selected tab (Welcome, first) is in the Tab order until the script moves it.
    echo "\t\t" . '<li' . ($thisId === 'pmssconfigure' ? ' class="pt-end"' : '') . '><a href="#' . $frameIdHtml . '" title="' . $frameTitleHtml . '"' . ($thisId === 'pmssconfigure' ? ' aria-label="Configure"' : '')
        . ' id="pmss-tab-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $thisId) . '" role="tab" aria-controls="' . $frameIdHtml . '" aria-selected="' . ($pmssTabFirst ? 'true' : 'false') . '" tabindex="' . ($pmssTabFirst ? '0' : '-1') . '"'
        . ' data-tab="' . htmlspecialchars(strtolower((string) $thisId), ENT_QUOTES, 'UTF-8') . '"'
        . ' onClick="loadFrame(\'' . $frameIdJs . '\', \'' . $frameUrlJs . '\'); setTimeout(\'setHeights();\', 500); ">' . $frameIconHtml . '<span class="pt-lbl">' .
        $frameTextHtml . '</span><i class="pt-mark" hidden></i></a></li>' . "\n";
    $pmssTabFirst = false;
}
?>
        </ul>
    <div id="content">            
<?php
foreach($frames AS $thisId => $thisFrame) {
    if (pmssFrameOpensInNewWindow($thisFrame)) {
        continue;
    }

    $frameIdHtml  = htmlspecialchars((string) $thisId, ENT_QUOTES, 'UTF-8');
    $frameUrlHtml = htmlspecialchars((string) ($thisFrame['url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $frameNameHtml = htmlspecialchars((string) ($thisFrame['linkText'] ?? $thisId), ENT_QUOTES, 'UTF-8');
    if ($thisId === 'pikkumies') {
        echo '<div id="pikkumies" class="tabs-container"><div class="pmss-pikkumies-header">'
            .'<a href="pikkumiesOpen.php?session=pm1" target="_blank" rel="noopener">Open in its own tab</a>';
        if ($pmssPikkumiesState['mode'] === 'power-user')
            echo '<a href="pikkumiesOpen.php?ui=hermes" target="_blank" rel="noopener">Open full Hermes</a>';
        echo '<span id="pmss-pikkumies-hint" hidden>Your network may be blocking Pikkumies.</span></div>'
            .'<iframe id="pikkumiesFrame" title="Pikkumies" width="100%" src="about:blank" frameborder="0"></iframe></div>';
    } elseif ($thisId != 'welcome') {
        echo "\t" . '<div id="' . $frameIdHtml . '" class="tabs-container"></div>' . "\n";
    } else {
        echo "\n\t" . '<div id="' . $frameIdHtml . '" class="tabs-container">
        <iframe id="' . $frameIdHtml . 'Frame" title="' . $frameNameHtml . '" width=100% height=100% src="' . $frameUrlHtml . '" frameborder="0"></iframe>
     </div>' . "\n";
    }
}
    
?>
    </div>
</div>










<script type="text/javascript">
var offsetWidth = 3;
var offsetDocumentHeight = -5;
var offsetHeight = -34;
var windowHeight = window.innerHeight || document.documentElement.clientHeight;

// The content area and every tab pane fill the window under the tab bar; the bar's rendered height is the offset.
function setHeights() {
        var bar = document.querySelector('#tabs > ul'), panes = document.querySelectorAll('#content > .tabs-container');
        if (bar && bar.offsetHeight) offsetHeight = -bar.offsetHeight;
        windowHeight = window.innerHeight || document.documentElement.clientHeight;
        var height = Math.max(0, windowHeight + offsetHeight) + 'px';
        document.getElementById('content').style.height = height;
        for (var i = 0; i < panes.length; i++) panes[i].style.height = height;
};

// Run the height cascade BEFORE invoking the tabs plugin. setHeights()
// needs neither jQuery nor the tabs plugin, so iframes get
// sized correctly even when either script failed to load.
setHeights();
setInterval(setHeights, 300);

try {
    $('#tabs').tabs({ onShow: setHeights });
} catch (e) {
    // Tabs plugin unavailable (external script load failure, blocked
    // request, etc.). Panel remains usable: heights are already set, tab
    // anchors still navigate via fragment links, loadFrame() handles tab
    // content insertion onClick.
}

function loadFrame(frameId, frameSrc) {
 if (frameId === 'pikkumies') { pmssPikkumiesOpen(); return; }
 if (document.getElementById(frameId) && document.getElementById(frameId).innerHTML === '') {
  var iframe = document.createElement('iframe');
  iframe.id = frameId + 'Frame'; iframe.title = frameId;
  iframe.width = '100%'; iframe.height = '100%'; iframe.frameBorder = '0';
  iframe.src = frameSrc;
  document.getElementById(frameId).appendChild(iframe);
  setHeights();
 }

}

<?php if ($pmssPikkumiesState['visible']): ?>
var pmssPikkumiesOrigin = <?= json_encode($pmssPikkumiesState['origin']) ?>;
var pmssPikkumiesReady = false, pmssPikkumiesPendingSwitch = false, pmssPikkumiesHintTimer;
var pmssPikkumiesWelcomeReloaded = false;
var pmssPikkumiesLastReconnect = 0;
var pmssPikkumiesLauncher = 'pikkumiesOpen.php?session=pm1';
function pmssPikkumiesNavigate(url) {
 pmssPikkumiesReady = false;
 document.getElementById('pikkumiesFrame').src = url;
}
function pmssPikkumiesOpen() {
 var frame = document.getElementById('pikkumiesFrame');
 if (!frame) return;
 if (!pmssPikkumiesReady) pmssPikkumiesNavigate(pmssPikkumiesLauncher);
 if (window.matchMedia('(pointer:fine)').matches) frame.focus();
}
document.getElementById('pikkumiesFrame').addEventListener('load', function () {
 if (this.src === 'about:blank') return;
 clearTimeout(pmssPikkumiesHintTimer);
 document.getElementById('pmss-pikkumies-hint').hidden = true;
 // The panel's own launcher page (setup, starting, not running) is this origin and never reports ready; only a frame
 // that went on to Pikkumies, or failed to, can be blocked by the network. A frame loaded behind another tab cannot
 // report ready either (the browser runs no animation frames in it), so the hint shows only while the tab is open;
 // opening the tab while not ready reloads the frame, which starts this timer again.
 var launcher = false;
 try { launcher = this.contentWindow.location.origin === location.origin; } catch (e) {}
 if (!launcher) pmssPikkumiesHintTimer = setTimeout(function () {
  if (!pmssPikkumiesReady && document.getElementById('pikkumies').offsetParent !== null) document.getElementById('pmss-pikkumies-hint').hidden = false;
 }, 12000);
 if (window.matchMedia('(pointer:fine)').matches && document.getElementById('pikkumies').offsetParent !== null) this.focus();
});
window.addEventListener('message', function (event) {
 if (event.origin === location.origin && event.source === document.getElementById('welcomeFrame').contentWindow
     && event.data === 'pmss:pikkumies-preload' && window.matchMedia('(pointer:fine)').matches) {
  var preload = document.getElementById('pikkumiesFrame');
  if (preload && preload.src === 'about:blank') pmssPikkumiesNavigate(pmssPikkumiesLauncher);
  return;
 }
 if (event.origin !== pmssPikkumiesOrigin || typeof event.data !== 'string') return;
 var frame = document.getElementById('pikkumiesFrame');
 var welcome = document.getElementById('welcomeFrame');
 var bar = null;
 try { bar = welcome.contentWindow.document.getElementById('pmss-pikkumies-bar-frame'); } catch (e) {}
 if (event.source === frame.contentWindow && event.data === 'pikkumies:ready') {
  pmssPikkumiesReady = true;
  document.getElementById('pmss-pikkumies-hint').hidden = true;
  if (pmssPikkumiesPendingSwitch) {
   pmssPikkumiesPendingSwitch = false;
   document.querySelector('a[href="#pikkumies"]').click();
   if (window.matchMedia('(pointer:fine)').matches) frame.focus();
  }
  if (welcome) {
   var disabled = null;
   try { disabled = welcome.contentWindow.document.getElementById('pmss-pikkumies-bar-disabled'); } catch (e) {}
   if (disabled && !pmssPikkumiesWelcomeReloaded) {
    pmssPikkumiesWelcomeReloaded = true;
    welcome.contentWindow.location.reload();
   } else welcome.contentWindow.postMessage('pikkumies:tab-ready', location.origin);
  }
  return;
 }
 if (event.source === frame.contentWindow && event.data === 'pikkumies:reconnect') {
  var now = Date.now();
  pmssPikkumiesNavigate(now - pmssPikkumiesLastReconnect >= 30000
   ? pmssPikkumiesLauncher : 'pikkumiesOpen.php?view=not-running');
  pmssPikkumiesLastReconnect = now;
  return;
 }
 if (!bar || event.source !== bar.contentWindow) return;
 if (event.data === 'pikkumies:ready') {
  welcome.contentWindow.postMessage('pikkumies:bar-ready', location.origin);
 } else if (event.data === 'pikkumies:switch') {
  if (!pmssPikkumiesReady) { pmssPikkumiesPendingSwitch = true; pmssPikkumiesNavigate(pmssPikkumiesLauncher); }
  else {
   document.querySelector('a[href="#pikkumies"]').click();
   if (window.matchMedia('(pointer:fine)').matches) frame.focus();
  }
 } else if (/^pikkumies:rows:[1-6]$/.test(event.data)) {
  // 52px is one row of the bar page's box; each extra row adds one 22px line.
  bar.style.height = (52 + 22 * (Number(event.data.slice(-1)) - 1)) + 'px';
 }
});
// A host rollback removes the marker while an old top frame may stay open.
setInterval(function () {
 fetch('pikkumiesOpen.php?view=status', {credentials:'same-origin',cache:'no-store'})
  .then(function (response) { return response.json(); })
  .then(function (state) { if (!state.visible) location.reload(); }).catch(function () {});
}, 30000);
<?php endif; ?>
</script>
<script type="text/javascript">
// Tab bar behaviour, in its own script so it runs whichever tabs plugin the page head loaded, or none: selection
// state for screen readers and styling, arrow keys, the address-bar hash, first-load progress, scroll arrows and the
// app state the Welcome and Configure pages report.
(function () {
 var bar = document.querySelector('#tabs > ul'), content = document.getElementById('content');
 if (!bar || !content || !document.addEventListener) return;
 var tabs = [], links = bar.querySelectorAll('a[role="tab"]'), plugin = !!(window.jQuery && jQuery.fn && jQuery.fn.tabs);
 for (var i = 0; i < links.length; i++) tabs.push(links[i]);
 if (!tabs.length) return;
 for (i = 0; i < bar.children.length; i++) bar.children[i].setAttribute('role', 'presentation');
 function paneOf(tab) { return document.getElementById(tab.getAttribute('aria-controls')); }
 function tabFor(id) { for (var n = 0; n < tabs.length; n++) if (tabs[n].getAttribute('aria-controls') === id) return tabs[n]; return null; }
 function label(tab) { var text = tab.querySelector('.pt-lbl'); return text ? text.textContent : tab.getAttribute('aria-controls'); }
 tabs.forEach(function (tab) {
  var pane = paneOf(tab);
  if (pane) { pane.setAttribute('role', 'tabpanel'); pane.setAttribute('aria-labelledby', tab.id); }
  tab.setAttribute('data-title', tab.title);
 });
 function tabOf(node) {
  while (node && node !== bar) { if (node.getAttribute && node.getAttribute('role') === 'tab') return node; node = node.parentNode; }
  return null;
 }

 // Scroll arrows show only on the side where tabs are out of view; keyboard users scroll by moving focus.
 var arrows = {};
 ['prev', 'next'].forEach(function (side) {
  var button = document.createElement('button'), icon = '#pmss-i-chevron-' + (side === 'prev' ? 'left' : 'right');
  button.type = 'button'; button.className = 'pt-scroll pt-' + side; button.hidden = true; button.tabIndex = -1;
  button.setAttribute('aria-hidden', 'true');
  button.innerHTML = '<svg class="pt-ico" aria-hidden="true" focusable="false"><use href="' + icon + '" xlink:href="' + icon + '"></use></svg>';
  button.addEventListener('click', function () { bar.scrollLeft += (side === 'prev' ? -1 : 1) * Math.max(120, bar.clientWidth * 0.7); edges(); });
  bar.parentNode.insertBefore(button, bar);
  arrows[side] = button;
 });
 function edges() {
  arrows.prev.hidden = bar.scrollLeft <= 1;
  arrows.next.hidden = bar.scrollLeft + bar.clientWidth >= bar.scrollWidth - 1;
 }
 function reveal(tab) {
  var item = tab.parentNode, left = item.offsetLeft, right = left + item.offsetWidth, room = arrows.prev.offsetWidth || 40;
  if (left < bar.scrollLeft + room) bar.scrollLeft = left - room;
  else if (right > bar.scrollLeft + bar.clientWidth - room) bar.scrollLeft = right - bar.clientWidth + room;
  edges();
 }
 bar.addEventListener('scroll', edges);
 window.addEventListener('resize', function () { edges(); if (window.setHeights) setHeights(); });

 // Selection: one tab is aria-selected and in the Tab order; the address bar names it (Welcome needs no name).
 function address(id) {
  var search = location.search.replace(/([?&])pmssr=1(&|$)/, function (all, lead, tail) { return tail ? lead : ''; }).replace(/[?&]$/, '');
  return location.pathname + search + (id === 'welcome' || !id ? '' : '#' + encodeURIComponent(id));
 }
 function select(tab) {
  var id = tab.getAttribute('aria-controls');
  tabs.forEach(function (other) {
   var on = other === tab, pane = paneOf(other);
   other.setAttribute('aria-selected', on ? 'true' : 'false');
   other.tabIndex = on ? 0 : -1;
   if (!plugin && pane) pane.style.display = on ? '' : 'none';
  });
  reveal(tab);
  progress();
  if (window.history && history.replaceState && address(id) !== location.pathname + location.search + location.hash)
   history.replaceState(null, '', address(id));
  askState();
 }
 bar.addEventListener('click', function (event) {
  var tab = tabOf(event.target);
  if (!tab) return;
  if (!plugin) event.preventDefault();
  select(tab);
 });
 // Arrow keys, Home and End move focus only (opening a tab loads its app); Enter or Space opens the focused tab.
 bar.addEventListener('keydown', function (event) {
  var at = tabs.indexOf(document.activeElement), key = event.key || event.keyCode, next = -1;
  if (at < 0) return;
  if (key === 'ArrowRight' || key === 'Right' || key === 39) next = (at + 1) % tabs.length;
  else if (key === 'ArrowLeft' || key === 'Left' || key === 37) next = (at - 1 + tabs.length) % tabs.length;
  else if (key === 'Home' || key === 36) next = 0;
  else if (key === 'End' || key === 35) next = tabs.length - 1;
  else if (key === ' ' || key === 'Spacebar' || key === 32) { event.preventDefault(); tabs[at].click(); return; }
  if (next < 0) return;
  event.preventDefault();
  tabs.forEach(function (tab, n) { tab.tabIndex = n === next ? 0 : -1; });
  tabs[next].focus();
  reveal(tabs[next]);
 });
 bar.addEventListener('focusout', function (event) {
  if (event.relatedTarget && bar.contains(event.relatedTarget)) return;
  tabs.forEach(function (tab) { tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1; });
 });

 // First load of a tab's app: a spinner on the tab and a progress line over the content until the frame loads; after
 // 15 seconds an honest note with a way to open the app on its own replaces the spinner.
 var note = document.createElement('div');
 note.className = 'pt-note'; note.hidden = true; note.setAttribute('role', 'status');
 content.appendChild(note);
 function progress() {
  var tab = bar.querySelector('a[aria-selected="true"]'), loading = !!tab && /\bpt-loading\b/.test(tab.className);
  content.className = content.className.replace(/\s*pt-busy/g, '') + (loading ? ' pt-busy' : '');
  if (!tab || note.getAttribute('data-for') !== tab.getAttribute('aria-controls')) note.hidden = true;
 }
 function busy(tab, frame, url) {
  if (!tab || !frame) return;
  var icon = tab.querySelector('.pt-ico use'), was = icon && icon.getAttribute('href'), pane = paneOf(tab), timer;
  function swap(ref) { if (icon) { icon.setAttribute('href', ref); icon.setAttribute('xlink:href', ref); } }
  function done() {
   clearTimeout(timer);
   frame.removeEventListener('load', done);
   tab.className = tab.className.replace(/\s*pt-loading/g, '');
   swap(was);
   if (pane) pane.removeAttribute('aria-busy');
   if (note.getAttribute('data-for') === tab.getAttribute('aria-controls')) note.hidden = true;
   progress();
  }
  tab.className += ' pt-loading';
  swap('#pmss-i-loader-circle');
  if (pane) pane.setAttribute('aria-busy', 'true');
  frame.addEventListener('load', done);
  timer = setTimeout(function () {
   tab.className = tab.className.replace(/\s*pt-loading/g, '');
   swap(was);
   progress();
   if (!url) return;
   note.innerHTML = '';
   note.appendChild(document.createTextNode(label(tab) + ' is taking a while to load. '));
   var open = document.createElement('a');
   open.href = url; open.target = '_blank'; open.rel = 'noopener'; open.textContent = 'Open it on its own';
   note.appendChild(open);
   note.setAttribute('data-for', tab.getAttribute('aria-controls'));
   note.hidden = tab.getAttribute('aria-selected') !== 'true';
  }, 15000);
  progress();
 }
 if (typeof window.loadFrame === 'function') {
  var load = window.loadFrame;
  window.loadFrame = function (frameId, frameSrc) {
   var pane = document.getElementById(frameId), had = pane && pane.querySelector('iframe');
   var result = load.apply(this, arguments), frame = pane && pane.querySelector('iframe'), tab = tabFor(frameId);
   if (frame && !had && tab) { frame.title = label(tab); busy(tab, frame, frameSrc); }
   return result;
  };
 }
 var welcome = document.getElementById('welcomeFrame');
 try {
  if (welcome && !(welcome.contentDocument && welcome.contentDocument.readyState === 'complete'
   && welcome.contentWindow.location.href !== 'about:blank')) busy(tabFor('welcome'), welcome, '');
 } catch (e) {}

 // App state: Welcome and Configure already read it for their own pages and post it here as {id: [word, tone]}.
 // A mark shows only when an app needs attention (a ring for a warning, a dot for a failure) and the state word joins
 // the tooltip and the tab's spoken name. Marks expire when nothing has reported for 10 minutes.
 var asked = 0, heard = 0;
 function askState() {
  if (!welcome || Date.now() - asked < 60000) return;
  asked = Date.now();
  try { welcome.contentWindow.postMessage('pmss:apps-state', location.origin); } catch (e) {}
 }
 function mark(tab, word, tone) {
  var dot = tab.querySelector('.pt-mark'), said = tab.querySelector('.pt-sr');
  if (!dot) return;
  tab.title = tab.getAttribute('data-title') + (word ? ' · ' + word : '');
  dot.hidden = !word || tone === 'run' || tone === '';
  dot.className = 'pt-mark' + (tone === 'bad' ? ' pt-bad' : '');
  if (!said) { said = document.createElement('i'); said.className = 'pt-sr'; tab.appendChild(said); }
  said.textContent = dot.hidden ? '' : ', ' + word;
 }
 window.addEventListener('message', function (event) {
  var data = event.data, frames = [welcome, document.getElementById('pmssconfigureFrame')], from = false;
  if (event.origin !== location.origin || !data || data.type !== 'pmss:apps-state' || typeof data.apps !== 'object') return;
  for (var n = 0; n < frames.length; n++) if (frames[n] && event.source === frames[n].contentWindow) from = true;
  if (!from) return;
  var apps = {};
  for (var id in data.apps) if (Object.prototype.hasOwnProperty.call(data.apps, id)) apps[id.toLowerCase()] = data.apps[id];
  heard = Date.now();
  tabs.forEach(function (tab) {
   var key = tab.getAttribute('data-tab'), app = apps[key === 'rutorrent' ? 'rtorrent' : key];
   if (key === 'welcome' && typeof data.attention === 'string') mark(tab, data.attention, data.attention ? 'warn' : 'run');
   else if (app && typeof app[0] === 'string') mark(tab, app[0], String(app[1] || ''));
  });
 });
 setInterval(function () {
  if (heard && Date.now() - heard > 600000) { heard = 0; tabs.forEach(function (tab) { mark(tab, '', ''); }); }
 }, 60000);

 // An address ending in a tab's name opens that tab; a name that is not a tab here falls back to Welcome.
 function fromHash() {
  var id = '';
  try { id = decodeURIComponent(location.hash.replace(/^#/, '')); } catch (e) {}
  var tab = id ? tabFor(id) : null;
  if (tab && tab.getAttribute('aria-selected') !== 'true') { tab.click(); return true; }
  if (tab) { select(tab); return true; }
  if (location.hash && window.history && history.replaceState) history.replaceState(null, '', address(''));
  return false;
 }
 window.addEventListener('hashchange', fromHash);
 if (!fromHash()) select(bar.querySelector('a[aria-selected="true"]') || tabs[0]);
})();
</script>

</body>
</html>
