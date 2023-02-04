<?php
/**
* Pulsed Media Seedbox Master Gui
*
* Copyright (C) 2010-2011 Aleksi Ursin / NuCode
* All rights reserved.
*
**/
if (!($frames = @file_get_contents('https://pulsedmedia.com/remote/guiFrames.php?v=2')) ) {
  include 'welcome.php'; include 'info.php'; die();
} else
 $frames = eval( unserialize( base64_decode( $frames ) ) );
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
// File formatting:
# This file is used for custom tabs in the web interface.
# Format is:
# appname|tooltip|label|url
# |       |       |     |
# |       |       |     \--- URL to the application
# |       |       \--------- Tab label
# |       \----------------- Hover text
# \------------------------- Internal name, must be alphanumeric and not start with a number

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
foreach($frames AS $thisId => $thisFrame)
    $styleList[] = '#' . $thisId;
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
foreach($frames AS $thisId => $thisFrame)
    echo "\t\t" . '<li><a href="#' . $thisId . '" title="' . $thisFrame['title'] . '" onClick="loadFrame(\'' . $thisId . '\', \'' . $thisFrame['url'] . '\'); setTimeout(\'setHeights();\', 500); "><span>' .
        $thisFrame['linkText'] . '</span></a></li>' . "\n";
?>
        </ul>
    <div id="content">            
<?php
foreach($frames AS $thisId => $thisFrame)
    if ($thisId != 'welcome') echo "\t" . '<div id="' . $thisId . '" class="tabs-container"></div>' . "\n";
	else  echo "\n\t" . '<div id="' . $thisId . '" class="tabs-container">
        <iframe id="' . $thisId . 'Frame" width=100% height=100% src="' . $thisFrame['url'] . '" frameborder="0"></iframe>
     </div>' . "\n";
    
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
        foreach($frames AS $thisId => $thisFrame)
                echo "$('#{$thisId}').height( windowHeight + offsetHeight );\n";
        ?>
};

$('#tabs').tabs({ onShow: function() { setHeights(); } });

setHeights();
setInterval('setHeights();', 500);

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
