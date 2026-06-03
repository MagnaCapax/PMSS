<?php
/**
 * PMSS: Master GUI, index frame loader.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010–2015.
 *
 * Responsibilities:
 *  - Build the local tab set from bundled customer-tree definitions.
 *  - Merge per-user custom tabs and enabled app tabs into the frame list.
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 */

$htmlHead = '';
$frames   = array();
$useLocalFrames = false;

if (file_exists(__DIR__.'/welcomeMessage.php')) require_once __DIR__.'/welcomeMessage.php';

/** Detect frames that must open outside the iframe tab container. */
function pmssFrameOpensInNewWindow(array $frame)
{
    return isset($frame['target']) && $frame['target'] === '_blank';
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
        'qbittorrent' => array(
            'url'      => 'qbittorrent/',
            'linkText' => 'qBittorrent',
            'title'    => 'qBittorrent - Torrent web UI',
        ),
        'deluge' => array(
            'url'      => 'deluge/',
            'linkText' => 'Deluge',
            'title'    => 'Deluge - Torrent web UI',
        ),
        'rclone' => array(
            'url'      => 'rclone/',
            'linkText' => 'Rclone',
            'title'    => 'Rclone Web UI',
        ),
        'jellyfin' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'jellyfin/web/index.html',
            'linkText' => 'Jellyfin',
            'title'    => 'Jellyfin - Media server',
        ),
        'radarr' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'radarr/',
            'linkText' => 'Radarr',
            'title'    => 'Radarr - Movie manager',
        ),
        'sonarr' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'sonarr/',
            'linkText' => 'Sonarr',
            'title'    => 'Sonarr - TV manager',
        ),
        'prowlarr' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'prowlarr/',
            'linkText' => 'Prowlarr',
            'title'    => 'Prowlarr - Indexer manager',
        ),
        'lidarr' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'lidarr/',
            'linkText' => 'Lidarr',
            'title'    => 'Lidarr - Music manager',
        ),
        'readarr' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'readarr/',
            'linkText' => 'Readarr',
            'title'    => 'Readarr - Book manager',
        ),
        'sabnzbd' => array(
            'url'      => $publicBase === '' ? '' : $publicBase.'sabnzbd/',
            'linkText' => 'SABnzbd',
            'title'    => 'SABnzbd - Usenet downloader',
        ),
        'invidious' => array(
            'url'      => 'apps/invidious/',
            'linkText' => 'Invidious',
            'title'    => 'Invidious - Video frontend',
        ),
    );
}

/** Return true when a lighttpd proxy fragment exposes the named app path. */
function pmssLocalFrameProxyFragmentMentionsApp($fragment, $app)
{
    $appPattern = preg_quote($app, '/');
    $pathPrefix = '(?:user-[^"\']+\/(?:apps\/)?|public-[^"\']+\/)?';
    return preg_match('/\^\/'.$pathPrefix.$appPattern.'(?:\(|\/|\$)/i', $fragment) === 1
        || preg_match('/["\']\/'.$pathPrefix.$appPattern.'(?:\/|["\'])/i', $fragment) === 1;
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
    // signal the service watchdog uses to decide whether the backend runs
    // (checkQbittorrentInstances.php -> pmssUserWatchdogRunService(...
    // 'qbittorrentEnable' ...)). A leftover .config/<app> dir from a disabled
    // service must NOT surface a tab: clicking it loads a backend that is not
    // running and the customer gets a raw 503. Per ADR 0021 #2: enabled
    // features only. Requiring BOTH the config dir and the enable flag is
    // conservative — it only ever removes false-positive tabs, never hides an
    // app the watchdog is actually keeping alive.
    $signals = array(
        'qbittorrent' => array('config' => '.config/qBittorrent', 'enable' => '.qbittorrentEnable'),
        'deluge'      => array('config' => '.config/deluge',      'enable' => '.delugeEnable'),
    );

    $home = rtrim($homePath, '/');
    foreach ($signals as $app => $paths) {
        if (!isset($definitions[$app]) || $definitions[$app]['url'] === '') {
            continue;
        }
        if (is_dir($home.'/'.$paths['config']) && is_file($home.'/'.$paths['enable'])) {
            $frames[$app] = $definitions[$app];
        }
    }

    return $frames;
}

$useLocalFrames = true;

if ($useLocalFrames) {
    // Bundled local tab set for the customer panel.
    $htmlHead = <<<EOF
<title>PM Seedbox</title>
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="pmssTabs.js"></script>
        <link rel="stylesheet" href="jquery.tabs.css" type="text/css" media="print, projection, screen">
EOF;

    $frames = array(
        'welcome' => array(
            'url'      => pmssLocalFrameWelcomeUrlBuild(),
            'linkText' => 'welcome',
            'title'    => 'Welcome to your seedbox. Basic information',
        ),
        'rutorrent' => array(
            'url'      => 'rutorrent/',
            'linkText' => 'ruTorrent',
            'title'    => 'ruTorrent - Torrent web UI',
        ),
        'filemanager' => array(
            'url'      => (file_exists('filemanager.php') ? 'filemanager.php' : 'ajax/'),
            'linkText' => 'File manager',
            'title'    => 'Manage your files',
        ),
        'info' => array(
            'url'      => 'info.php',
            'linkText' => 'info',
            'title'    => 'Information, quota, server RAM',
        ),
        // Wiki is an in-page iframe tab, not a new window. wiki.pulsedmedia.com
        // sends `CSP: frame-ancestors 'self' https://*.pulsedmedia.com`, so it
        // frames cleanly from any per-user seedbox subdomain. (Reverts the
        // b4d284f8 `target=_blank` workaround for the old SAMEORIGIN block,
        // which no longer exists. Per ADR 0021: top frame = in-page tabs only.)
        'wiki' => array(
            'url'      => 'https://wiki.pulsedmedia.com',
            'linkText' => 'wiki',
            'title'    => 'Pulsed Media Wiki',
        ),
    );
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
<?=$htmlHead;?>
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

.tabs-nav a,
.tabs-nav a span {
    background: #3d3d3d;
    color:#fff;
    font-family: Arial;
    font-size: 14px;
    border-radius: 4px;
    font-weight: 30;
    padding: 6px 14px;
    width: auto;
    height: auto;
    min-width: 0;
    min-height: 0;
    background-image: none;
    display: flex;
    align-items: center;
    justify-content: center; 
    cursor: pointer;
}

.tabs-nav a:hover, .tabs-nav span:hover {color:#d1d1d1;text-decoration:underline;}
.tabs-selected a:hover, .tabs-selected span:hover {text-decoration:none; color:#fff;cursor:default;}
h6{color: #26c2ff; }
hr {background-color: #4b4b4b;}
#content {border: none;}

.tabs-nav {
    background: #1f1f1f;
    display: flex;
    justify-content: center;
}

.full_body {
    background: none;
    border: none;
    margin-top: 15px;
}

<?php
// Load custom frames from ~/.customFrames
// Each non-comment line defines one additional tab in the GUI:
//   appname|tooltip|label|url
//   |       |       |     |
//   |       |       |     \--- URL to the application
//   |       |       \--------- Tab label
//   |       \----------------- Hover text (tooltip)
//   \------------------------- Internal name, must be alphanumeric and
//                              must not start with a number.

$frameData = array();
if (file_exists('../.customFrames')) {
    $file = new SplFileObject('../.customFrames');
    while (!$file->eof()) {
        $line = trim($file->fgets());
        if ($line === '' || strpos($line, "#") === 0) continue;
        $frameArray = explode("|", $line);
        if (count($frameArray) < 4 || $frameArray[3] === '') continue; // Drop invalid lines
        $frameData[$frameArray[0]] = array(
            'title' => $frameArray[1],
            'linkText' => $frameArray[2],
            'url' => $frameArray[3]
        );
    }
    $file = null;
}
foreach (pmssLocalFrameInstalledAppFramesRead() as $app => $frame) {
    if (!isset($frames[$app]) && !isset($frameData[$app])) {
        $frameData[$app] = $frame;
    }
}
foreach (pmssLocalFrameProxyAppFramesRead() as $app => $frame) {
    if (!isset($frames[$app]) && !isset($frameData[$app])) {
        $frameData[$app] = $frame;
    }
}
if (file_exists('../.delugeEnable') && file_exists('deluge.php') && !isset($frames['deluge']) && !isset($frameData['deluge'])) {
    $frameData['deluge'] = array(
        'title' => 'Deluge - Torrent web UI',
        'linkText' => 'Deluge',
        'url' => 'deluge/'
    );
}
$frames = array_merge($frames, $frameData);

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
        <ul class="tabs-nav">
<?php
foreach($frames AS $thisId => $thisFrame) {
    if (pmssFrameOpensInNewWindow($thisFrame)) {
        echo "\t\t" . '<li><a href="' . $thisFrame['url'] . '" title="' . $thisFrame['title'] . '" target="_blank" rel="noopener noreferrer"><span>' .
            $thisFrame['linkText'] . '</span></a></li>' . "\n";
        continue;
    }

    echo "\t\t" . '<li><a href="#' . $thisId . '" title="' . $thisFrame['title'] . '" onClick="loadFrame(\'' . $thisId . '\', \'' . $thisFrame['url'] . '\'); setTimeout(\'setHeights();\', 500); "><span>' .
        $thisFrame['linkText'] . '</span></a></li>' . "\n";
}
?>
        </ul>
    <div id="content">            
<?php
foreach($frames AS $thisId => $thisFrame) {
    if (pmssFrameOpensInNewWindow($thisFrame)) {
        continue;
    }

    if ($thisId != 'welcome') {
        echo "\t" . '<div id="' . $thisId . '" class="tabs-container"></div>' . "\n";
    } else {
        echo "\n\t" . '<div id="' . $thisId . '" class="tabs-container">
        <iframe id="' . $thisId . 'Frame" width=100% height=100% src="' . $thisFrame['url'] . '" frameborder="0"></iframe>
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
var windowHeight = $(window).height();

function setHeights() {
        windowHeight = $(window).height();
        $('#content').height( windowHeight + offsetHeight );
        <?php
        foreach($frames AS $thisId => $thisFrame) {
            if (pmssFrameOpensInNewWindow($thisFrame)) {
                continue;
            }

                echo "$('#{$thisId}').height( windowHeight + offsetHeight );\n";
        }
        ?>
};

// Run the height cascade BEFORE invoking the tabs plugin. setHeights()
// depends only on jQuery core, not on the tabs plugin, so iframes get
// sized correctly even when the plugin script failed to load.
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
 var frameIds = frameId + frameSrc;
 if ($('#' + frameId).html() == '') {
  $('#' + frameId).html('<iframe id="' + frameId + 'Frame" width=100% height=100% src="' + frameSrc + '" frameborder="0"></iframe>');
  setHeights();
 }

}
</script>

</body>
</html>
