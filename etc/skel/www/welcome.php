<?php
/**
 * PMSS: User Frontend Welcome Page
 * This is the actual index / first page user sees with all the buttons etc.
 *
 * Original concept and implementation: Aleksi Ursin, 2010–2011.
 *
 * #TODO Major refactoring; https://github.com/MagnaCapax/PMSS/issues/64
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 *
 * @author  Pulsed Media Dev Team
 * @package PMSS
 * @version 1.0
 */

if (isset($_GET['quota'])) {
    $quotaInfo = urldecode($_GET['quota']);
    $quotaInfo = str_replace('\\', '', $quotaInfo); // Serialized data might be malformed with \ chars!
    $quotaInfo = unserialize($quotaInfo);
} else {
    $quotaInfo = array();
}

if (file_exists('../.bonusQuota')) {
    $bonusQuota = (int)@file_get_contents('../.bonusQuota');
} else {
    $bonusQuota = 0;
}

$vendorDefault = array(
    'name'      => 'Pulsed Media',
    'pulsedBox' => true
);

if (file_exists('/etc/seedbox/config/vendor')) {
    $vendor = @file_get_contents('/etc/seedbox/config/vendor');
    $vendor = @unserialize($vendor);
    if (count($vendor) == 0 || !isset($vendor['name']) || empty($vendor['name'])) {
        $vendor = $vendorDefault;
    }
} else {
    $vendor = $vendorDefault;
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title><?= $vendor['name']; ?> Seedbox</title>
    <!-- Stylesheets -->
    <link href="https://static.pulsedmedia.com/wc/css/screen.css" rel="stylesheet" type="text/css" media="screen" />
    <!-- Javascript -->
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js"></script>

<?php
// April Fool's joke (2022) - disabled after April 1st
if (time() < mktime(13, 0, 0, 4, 2, 2022)) {
?>
    <style>
        #meter-disk-value {
            background: linear-gradient(124deg, #ff2400, #e81d1d, #e8b71d, #e3e81d, #1de840, #1ddde8, #2b1de8, #dd00f3, #dd00f3);
            background-size: 1800% 1800%;
            border-radius: 10px;
            -webkit-animation: rainbow 8s ease infinite;
            animation: rainbow 8s ease infinite;
        }
        @keyframes rainbow {
            0%   { background-position: 0% 82%; }
            50%  { background-position: 100% 19%; }
            100% { background-position: 0% 82%; }
        }
        .portfoliobox p,
        .portfoliobox h6,
        .portfoliobox a,
        .portfoliobox span {
            font-family: 'Comic Sans MS', sans-serif;
            font-style: italic;
            font-weight: bold;
            transform: rotate(-1.5deg);
            -webkit-transform: rotate(-1.5deg);
            -moz-transform: rotate(-1.5deg);
        }
        #meter-disk-holder { border-radius: 10px; }
    </style>
<?php
}
?>

    <!--[if lt IE 7]>
        <script type="text/javascript" src="https://static.pulsedmedia.com/wc/js/unitpngfix.js"></script>
    <![endif]-->
    <!--[if lte IE 6]>
        <link href="https://static.pulsedmedia.com/wc/css/ie6.css" rel="stylesheet" type="text/css" />
    <![endif]-->
</head>
<body>
    <div id="wrap">
        <div id="full_page">
            <div class="full_top_nohd"><!-- top design --></div>
            <div class="full_body">
                <div class="portfoliobox">
                    <div class="portfolioimg">
                        <?php
                        $welcomeHeadingUrl = 'https://pulsedmedia.com/remote/welcomeHeadingText.php';
                        $welcomeContext = stream_context_create(array(
                            'http' => array(
                                'timeout'    => 5,
                                'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)'
                            )
                        ));
                        $welcomeHeading = @file_get_contents($welcomeHeadingUrl, false, $welcomeContext);
                        if ($welcomeHeading !== false) {
                            echo $welcomeHeading;
                        }

                        if (file_exists('/etc/seedbox/config/vendorWelcome')) {
                            echo @file_get_contents('/etc/seedbox/config/vendorWelcome');
                        }
                        ?>
                        <h6>Basic Usage</h6>
                        <p><b>watch directory</b><br />
                           &nbsp; just upload torrents to this directory to start them automatically.
                        </p>
                        <ul>
                            <li><a href="rutorrent/">access ruTorrent</a></li>
                            <li><a href="data/"><b>access Data directory directly for HTTP downloads</b></a></li>
                            <hr />
                            <li><a href="https://the.earth.li/~sgtatham/putty/latest/x86/putty.exe">Download Putty (SSH access)</a></li>
                            <li><a href="https://winscp.net/download/WinSCP-5.21.6-Setup.exe" target="_blank">Download WinSCP (SFTP/SCP access)</a></li>
                            <li><a href="https://pulsedmedia.com/pulsedBox.air" title="pulsedBox :: Seedbox on your desktop">Download pulsedBox - Seedbox on your desktop (Current version 0.51)</a></li>
                            <li><a href="https://get.adobe.com/air/" title="Adobe AIR Installation package">Download Adobe AIR framework</a></li>
                        </ul>
                        <b>SFTP/FTP Client options</b>
                        <ul>
                            <li><a href="https://www.smartftp.com/get/SFTPMSI64.exe">Download SmartFTP (FTP access)</a> (Allows multipart/multithreading transfers) - Shareware</li>
                            <li><a href="https://filezilla-project.org/download.php?platform=win64" target="_blank">FileZilla - Popular opensource client</a></li>
                            <li><a href="https://www.bitkinex.com/ftp/client/bitkinex323.exe" target="_blank">BitKinex - Popular All-In-One SFTP, FTP, WebDAV client. Freeware</a></li>
                        </ul>

<?php
if (file_exists('/usr/local/bin/deluged') && file_exists('deluge.php')) {
?>
                        <h6>Deluge</h6>
                        <p>Deluge default password: <b>pulsedDeluge</b> — <b>Please change this immediately when accessing</b></p>
<?php
    if (!file_exists('../.delugeEnable')) {
?>
                        <input type="button" name="delugeStart" value="Start Deluge" onClick="$.ajax({url: 'deluge.php?action=start', success: function() { alert('Deluge starting, remember to change default password of pulsedDeluge to something else. Accessible at /deluge-USERNAME/. Refresh GUI to see tab'); location.reload(true); }});" />
<?php
    } else {
?>
                        <input type="button" name="delugeDisable" value="Disable Deluge" onClick="$.ajax({url: 'deluge.php?action=disable', success: function() { location.reload(true); }});" />
                        <input type="button" name="delugeRestart" value="Restart Deluge" onClick="$.ajax({url: 'deluge.php?action=restart', success: function() { alert('Deluge restart.'); }});" />
<?php
    }
}

if (file_exists('/usr/bin/rclone') && file_exists('rclone.php')) {
?>
                        <h6>Rclone Web UI</h6>
                        <p>Rclone password is the same as your web access password</p>
<?php
    if (!file_exists('../.rcloneEnable')) {
?>
                        <input type="button" name="rcloneStart" value="Start Rclone" onClick="$.ajax({url: 'rclone.php?action=start', success: function() { alert('Rclone starting, access at /user-USERNAME/rclone. Refresh GUI to see tab'); location.reload(true); }});" />
<?php
    } else {
?>
                        <input type="button" name="rcloneDisable" value="Disable Rclone" onClick="$.ajax({url: 'rclone.php?action=disable', success: function() { alert('Rclone disabled.'); location.reload(true); }});" />
                        <input type="button" name="rcloneRestart" value="Restart Rclone" onClick="$.ajax({url: 'rclone.php?action=restart', success: function() { alert('Rclone restart.'); }});" />
<?php
    }
}

if (file_exists('/usr/bin/qbittorrent-nox') && file_exists('qbittorrent.php')) {
?>
                        <h6>qBittorrent</h6>
                        <p>qBittorrent username is your own username and password is <code>adminadmin</code> by default. Change password once logged in. If you get 503, try restarting Lighttpd — port may have changed.</p>
<?php
    if (!file_exists('../.qbittorrentEnable')) {
?>
                        <input type="button" name="qbittorrentStart" value="Start qBittorrent" onClick="$.ajax({url: 'qbittorrent.php?action=start', success: function() { alert('qBittorrent starting, access at /user-USERNAME/qbittorrent/ — Refresh GUI to see tab'); location.reload(true); }});" />
<?php
    } else {
?>
                        <input type="button" name="qbittorrentDisable" value="Disable qBittorrent" onClick="$.ajax({url: 'qbittorrent.php?action=disable', success: function() { alert('qBittorrent disabled.'); location.reload(true); }});" />
                        <input type="button" name="qbittorrentRestart" value="Restart qBittorrent" onClick="$.ajax({url: 'qbittorrent.php?action=restart', success: function() { alert('qBittorrent restart.'); }});" />
<?php
    }
}
?>

                        <h6>rTorrent</h6>
                        <input type="button" name="rtorrentRestart" value="Restart rTorrent" onClick="$.ajax({url: 'rtorrentRestart.php', success: function() { alert('rTorrent restart request sent, please allow up to 2 minutes for restart to happen.'); }});" />
<?php
if (file_exists('lighttpdRestart.php')) {
?>
                        <h6>Lighttpd</h6>
                        <input type="button" name="lighttpdRestart" value="Restart Lighttpd" onClick="alert('Lighttpd restart might take a couple of minutes'); $.ajax({url: 'lighttpdRestart.php?action=confirm-restart', success: function() {} });" />
<?php
}

if (file_exists('openvpn-config.tgz')) {
?>
                        <hr />
                        <h6>OpenVPN ** Beta</h6>
                        <p>OpenVPN support has been added. You can download configuration below. Install OpenVPN from <a href="https://openvpn.net/download-open-vpn/" title="OpenVPN Packages">OpenVPN.net</a>.</p>
                        <p>You can open the tarball using WinRAR for example. Put the config files under OpenVPN config dir, e.g. <i>C:\Program Files\OpenVPN\config</i>. Login is the same as FTP/SFTP.</p>
                        <p><a href="openvpn-config.tgz" title="OpenVPN Configuration">OpenVPN Config Files</a>.</p>
<?php
}

if ($vendor['pulsedBox'] == true) {
?>
                        <hr />
                        <h6 style="color: red; font-weight: bold;">pulsedBox NEW alpha version</h6>
                        <p>We have converted the pulsedBox application to ElectronJS framework — this is still early alpha but you can already test it. .torrent upload via system open does not work currently, but drag'n'drop to ruTorrent does. Please let us know your feedback, bug reports, etc. by contacting support.</p>
                        <p><a href="https://pulsedmedia.com/pulsedBox-download/pulsedBox.exe">Download pulsedBox alpha version for Windows</a></p>

                        <h6>pulsedBox :: Seedbox on your desktop</h6>
                        <p>We have created an <a href="https://www.adobe.com/products/air/" target="_blank">Adobe AIR</a> application to bring your seedbox to the Desktop! This makes a Pulsed Media seedbox work like a desktop application — directly adding torrents from websites, folders on your computer, etc!</p>
                        <p>This software is still in early beta stages, with several known usage issues. Wrong login credentials cause a blank page, and you have to manually associate .torrent files to the application.</p>
                        <p>To install, first download and run <a href="https://get.adobe.com/air/" title="Adobe AIR Installation package">Adobe AIR</a> package, then <a href="https://pulsedmedia.com/pulsedBox.air" title="pulsedBox :: Seedbox on your desktop">pulsedBox AIR</a> package.</p>
                        <p style="color: red;">There is an issue with the package due to certs — please check <a href="https://wiki.pulsedmedia.com/index.php/Installing_pulsedBox" title="Pulsed Media Wiki">wiki pulsedBox installation</a> information to install the package.</p>
<?php
}
?>

                        <h6>IRC - Internet Relay Chat</h6>
                        <p>You may come and chat with other Pulsed Media users and staff at IRC! Just click the <i>"Chat"</i> tab or login via SSH and type <i>"irssi"</i> — which has been configured on most servers to auto-join the correct network and channel.</p>
                        <p>Our IRC channel is #PulsedMedia on Freenode network.</p>
                    </div>

                    <div class="portfoliodesc">
                        <?php
                        echo quotaCreateSection($quotaInfo, $bonusQuota);

                        if (@file_exists('../.trafficLimit')) {
                            $trafficLimit = (int)trim(@file_get_contents('../.trafficLimit'));
                            if (@file_exists('../.trafficData')) {
                                $trafficData = @unserialize(trim(@file_get_contents('../.trafficData')));
                                trafficCreateSection($trafficData, $trafficLimit);
                            } else {
                                $trafficLimit = number_format($trafficLimit);
                                echo "Traffic limit: {$trafficLimit} GiB<br />";
                            }
                        }

                        echo passthru('systemctl status user-$(\'/usr/bin/id\' -u).slice|grep -m1  "Memory: "');

                        if (@file_exists('../.billingId')) {
                            $billingId = (int)@file_get_contents('../.billingId');
                            if ($billingId > 0) {
                                echo <<<EOF
                                <h6>Need more resources?</h6>
                        <p>Need more disk space, traffic, or RAM?</p>
                                <p>You can upgrade fast and easy — activation usually within few minutes! Just check out your <a href="https://pulsedmedia.com/clients/upgrade.php?type=configoptions&id={$billingId}" target="_blank">Upgrade Options!</a></p>
EOF;
                            }
                        }
                        ?>

                        <h6>Announcements</h6>
                        <ul>
<?php
$rssUrl = 'http://pulsedmedia.com/clients/announcementsrss.php';
$rssContext = stream_context_create(array(
    'http' => array(
        'timeout'    => 5,
        'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)'
    )
));
$rssRaw = @file_get_contents($rssUrl, false, $rssContext);
if ($rssRaw !== false) {
    $rssXml = @simplexml_load_string($rssRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($rssXml !== false) {
        $rssFeed = json_decode(json_encode($rssXml), true);
        if (isset($rssFeed['channel']['item']) && is_array($rssFeed['channel']['item'])) {
            $items = array_slice($rssFeed['channel']['item'], 0, 4, true);
            foreach ($items as $thisItem) {
                if (!isset($thisItem['pubDate'], $thisItem['link'], $thisItem['title'])) {
                    continue;
                }
                $thisItem['pubDate'] = date('d/m', strtotime($thisItem['pubDate']));
                $title = htmlspecialchars($thisItem['title']);
                $link  = $thisItem['link'];
                echo "<li>({$thisItem['pubDate']}) <a href=\"{$link}\" target=\"_blank\">{$title}</a></li>\n";
            }
        }
    }
}
?>
                        </ul>

                        <h6>Need support?</h6>
                        <ul>
                            <li><a href="https://pulsedmedia.com/clients/knowledgebase.php" title="Browse Pulsed Media Knowledgebase">Browse Knowledgebase</a></li>
                            <li><a href="http://wiki.pulsedmedia.com" title="Pulsed Media Wiki">Browse Wiki</a></li>
                            <li><a href="https://discord.gg/cGBz52HJtx" target="_blank" title="Join Pulsed Media on Discord">Discord</a></li>
                            <li>Technical: <a href="mailto:support@pulsedmedia.com" title="E-Mail Support">support@pulsedmedia.com</a></li>
                            <li>Billing: <a href="mailto:billing@pulsedmedia.com" title="E-Mail Billing">billing@pulsedmedia.com</a></li>
                        </ul>

                        <br /><br /><br /><br /><br /><br /><br /><br />
                    </div>
                </div>
            </div>
            <div class="full_bottom"></div>
        </div>
    </div>

    <script type="text/javascript">
        setTimeout(function(){ location = ''; }, 180000);
    </script>
</body>
</html>

<?php
function bonusQuotaDisplay($bonusQuota) {
    if ($bonusQuota != 0) {
        return '<b>BONUS QUOTA:</b> ' . number_format($bonusQuota) . ' GiB<br />';
    }
    return '';
}

function trafficCreateSection($trafficData, $trafficLimit) {
    if (count($trafficData) == 0) return;

    $trafficUsed = round($trafficData['raw']['month']);
    $percent = round((($trafficUsed / 1024) / $trafficLimit) * 100);
    $trafficUsed = round($trafficUsed / 1024) . " GiB";

    if ($percent > 100) {
        $warning = '<br /><b style="color: red;">OVER TRAFFIC LIMIT WARNING - REDUCED BANDWIDTH</b><br />You are beyond your traffic limit. Consider upgrading your plan or adding extra traffic.<br />Datacenter external outbound (TO internet) bandwidth limited to 100 Mbps. Datacenter internal and inbound bandwidth is unrestricted.';
    } else {
        $warning = '';
    }

    $titleText = "{$trafficUsed} / {$trafficLimit} GiB";
    $gauge = createGauge($titleText, $titleText, $percent);

    echo <<<EOF
    <h6>Traffic Info</h6>
    {$gauge}
    {$warning}
    This is rolling past 30 days, <a href="http://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/" target="_blank">read more</a>.
    <hr />
EOF;
}

function createGauge($titleText, $footerText, $percent, $percentMax = 0) {
    if ($percentMax == 0) $percentMax = $percent;
    $gaugeBackgroundColor = gaugeColor($percent);

    $template = <<<EOF
    <table style="margin: 0; padding: 0;">
        <tr>
            <td id="meter-disk-td" title="{$titleText}">
                <div id="meter-disk-holder">
                    <span id="meter-disk-text" style="overflow-x: visible; overflow-y: visible;">{$percent}%</span>
                    <div id="meter-disk-value" style="float: left; width: {$percentMax}%; background-color: #{$gaugeBackgroundColor}; visibility: visible;">&nbsp;</div>
                </div>
            </td>
        </tr>
    </table>
    <span style="font-size: 1.05em; float: right; text-align: right; line-height: 13px;">{$footerText}</span>
EOF;
    return $template;
}

function gaugeColor($percent) {
    $startColor = array(hexdec('99'), hexdec('E6'), hexdec('99'));
    $endColor   = array(hexdec('EE'), hexdec('99'), hexdec('99'));
    $differenceColor = array(
        $startColor[0] - $endColor[0],
        $startColor[1] - $endColor[1],
        $startColor[2] - $endColor[2]
    );

    if ($percent > 100) {
        return 'FF4040';
    }

    $offsetColor = array(
        round($differenceColor[0] * ($percent / 100)),
        round($differenceColor[1] * ($percent / 100)),
        round($differenceColor[2] * ($percent / 100))
    );

    $chosenColor = array(
        $startColor[0] - $offsetColor[0],
        $startColor[1] - $offsetColor[1],
        $startColor[2] - $offsetColor[2]
    );

    return dechex($chosenColor[0]) . dechex($chosenColor[1]) . dechex($chosenColor[2]);
}

function quotaCreateSection($quotaInfo, $bonusQuota = 0) {
    if (count($quotaInfo) == 0) return '';

    $freeSpace  = $quotaInfo['freeSpace'];
    $hardLimit  = $quotaInfo['hardLimit'];
    $totalSpace = $quotaInfo['totalSpace'];
    $usedBytes  = $quotaInfo['usedBytes'];

    if ($freeSpace == 0 || $hardLimit == 0 || $totalSpace == 0 || $usedBytes == 0) {
        return '<b>Warning:</b> Quota info is missing. If this persists for more than an hour, contact support.';
    }

    $percent = round(($usedBytes / $totalSpace) * 100, 1);
    $percentFromBurst = round(($usedBytes / $hardLimit) * 100);
    if ($percent < 100) {
        $percentFromBurst = round(($usedBytes / $totalSpace) * 100, 1);
    }

    $readableUsed   = filesize2HumanReadable($usedBytes);
    $readableQuota  = filesize2HumanReadable($totalSpace);
    $readableBurst  = filesize2HumanReadable($hardLimit);

    $bonusQuotaDisplay = ($bonusQuota != 0) ? '<br />Bonus disk space: ' . number_format($bonusQuota) . ' GiB' : '';

    if ($percent > 100) {
        $warning = <<<EOF
        <br /><b style="color: red;">OVER QUOTA WARNING</b><br />
        If you go over your burst limit, some applications will not operate and will shut down automatically, and will not restart until you are within quota limit. You are allowed to burst quota up to 7 days.
EOF;
    } else {
        $warning = '';
    }

    $titleText = "{$readableUsed}/{$readableQuota}";
    if ($percent > 100) $titleText .= " Burst limit: {$readableBurst}";

    $gauge = createGauge($titleText, $titleText . $bonusQuotaDisplay, $percent, $percentFromBurst);

    return <<<EOF
<h6>Quota Info</h6>
{$gauge}
{$warning}
<hr />
EOF;
}

function filesize2HumanReadable($bytes, $precision = 2) {
    $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
