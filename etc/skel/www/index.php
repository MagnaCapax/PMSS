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

/** Detect frames that must open outside the iframe tab container. */
function pmssFrameOpensInNewWindow(array $frame)
{
    return isset($frame['target']) && $frame['target'] === '_blank';
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
        'jellyfin' => pmssLocalFrameDefinition($publicBase === '' ? '' : $publicBase.'jellyfin/web/index.html', 'Jellyfin', 'Jellyfin - Media server'),
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
<title>PM Seedbox</title>
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
    background: #fff;
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
foreach (array(pmssLocalFrameInstalledAppFramesRead(), pmssLocalFrameProxyAppFramesRead()) as $pmssCandidateFrames) {
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
    // Frame fields can come from the customer-owned .customFrames file;
    // escape for the HTML and JS-in-attribute contexts below.
    $frameTitleHtml = htmlspecialchars((string) ($thisFrame['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $frameTextHtml  = htmlspecialchars((string) ($thisFrame['linkText'] ?? ''), ENT_QUOTES, 'UTF-8');
    $frameUrlHtml   = htmlspecialchars((string) ($thisFrame['url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $frameIdHtml    = htmlspecialchars((string) $thisId, ENT_QUOTES, 'UTF-8');
    // JS string context: escape backslashes/quotes first, then HTML-escape
    // for the surrounding attribute.
    $frameIdJs  = htmlspecialchars(str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $thisId), ENT_QUOTES, 'UTF-8');
    $frameUrlJs = htmlspecialchars(str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) ($thisFrame['url'] ?? '')), ENT_QUOTES, 'UTF-8');

    if (pmssFrameOpensInNewWindow($thisFrame)) {
        echo "\t\t" . '<li><a href="' . $frameUrlHtml . '" title="' . $frameTitleHtml . '" target="_blank" rel="noopener noreferrer"><span>' .
            $frameTextHtml . '</span></a></li>' . "\n";
        continue;
    }

    echo "\t\t" . '<li><a href="#' . $frameIdHtml . '" title="' . $frameTitleHtml . '" onClick="loadFrame(\'' . $frameIdJs . '\', \'' . $frameUrlJs . '\'); setTimeout(\'setHeights();\', 500); "><span>' .
        $frameTextHtml . '</span></a></li>' . "\n";
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
    if ($thisId != 'welcome') {
        echo "\t" . '<div id="' . $frameIdHtml . '" class="tabs-container"></div>' . "\n";
    } else {
        echo "\n\t" . '<div id="' . $frameIdHtml . '" class="tabs-container">
        <iframe id="' . $frameIdHtml . 'Frame" width=100% height=100% src="' . $frameUrlHtml . '" frameborder="0"></iframe>
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
