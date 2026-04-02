<?php
/**
 * PMSS: Master GUI, index frame loader.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010–2015.
 *
 * Responsibilities:
 *  - Fetch remote frame definitions from pulsedmedia.com when available.
 *  - Fallback to a local tab set when remote frames cannot be loaded.
 *  - Merge per-user custom tabs from ~/.customFrames (one tab per line).
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 */

$htmlHead = '';
$frames   = array();
$useLocalFrames = false;

/** Detect frames that must open outside the iframe tab container. */
function pmssFrameOpensInNewWindow(array $frame): bool
{
    return isset($frame['target']) && $frame['target'] === '_blank';
}

// Remote frames can be disabled explicitly for debugging or fully offline
// deployments by exporting PMSS_DISABLE_REMOTE_FRAMES=1.
if (!getenv('PMSS_DISABLE_REMOTE_FRAMES')) {
    $framesUrl = 'https://pulsedmedia.com/remote/guiFrames.php?v=2';
    $context = stream_context_create(array(
        'http' => array(
            'timeout'    => 5,
            'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)'
        )
    ));

    $remoteFrames = @file_get_contents($framesUrl, false, $context);
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

if ($useLocalFrames) {
    // Minimal local tab set used when remote frames are unavailable.
    // This keeps the familiar tabbed GUI layout even when pulsedmedia.com
    // is unreachable or remote frames are explicitly disabled.
    $htmlHead = <<<EOF
<title>PM Seedbox</title>
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="https://static.pulsedmedia.com/jquery.tabs.pack.js"></script>
        <link rel="stylesheet" href="https://static.pulsedmedia.com/jquery.tabs.css" type="text/css" media="print, projection, screen">
EOF;

    $frames = array(
        'welcome' => array(
            'url'      => 'welcome.php',
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
        'wiki' => array(
            'url'      => 'https://wiki.pulsedmedia.com',
            'linkText' => 'wiki',
            'title'    => 'Pulsed Media Wiki',
            'target'   => '_blank',
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
 top: 24px;
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
    border-radius: 4px;
    font-weight: 30;
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
        if (strpos($line, "#") === 0) continue;
        $frameArray = explode("|", $line);
        if (!$frameArray[3]) continue; // Drop invalid lines
        $frameData[$frameArray[0]] = array(
            'title' => $frameArray[1],
            'linkText' => $frameArray[2],
            'url' => $frameArray[3]
        );
    }
    $file = null;
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
        <ul>
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
var offsetHeight = -24;
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

$('#tabs').tabs({ onShow: function() { setHeights(); } });

setHeights();
setInterval('setHeights();', 300);

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
