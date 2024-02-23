<?php
/**
 * PMSS: User Frontend Welcome Page
 * This is the actual index / first page user sees with all the buttons etc.
 *
 * #TODO Major refactoring; https://github.com/MagnaCapax/PMSS/issues/64
 *
 * Copyright (C) 2010-2024 Magna Capax Finland Oy
 *
 **/
require './welcome_includes/utils.php';    // Include utility functions
require './welcome_includes/configLoader.php'; //  Loads vendor details and quata info
?>

<!DOCTYPE html
    PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>
        <?= $vendor['name']; ?> Seedbox
    </title>
    <!--Stylesheets-->
    <link href="https://static.pulsedmedia.com/wc/css/screen.css" rel="stylesheet" type="text/css" media="screen" />

    <?php
    // Old april fool's joke ... rerun something similar? ;)
    $currentDate = date('m-d');
    if ($currentDate == '04-01') {
        ?>
        <link href="./welcome_includes/aprilFoolsStyles.css" rel="stylesheet" type="text/css" />
        <?php
    }
    ?>

    <!--Javascript-->
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js"></script>

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
                        <?= @file_get_contents('https://pulsedmedia.com/remote/welcomeHeadingText.php'); ?>

                        <?php
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
                            <li><a href="http://the.earth.li/~sgtatham/putty/latest/x86/putty.exe">Download Putty (SSH
                                    access)</a></li>
                            <li><a href="https://winscp.net/download/winscp575setup.exe" target="_blank">Download WinSCP
                                    (SFTP/SCP access)</a></li>

                            <li><a href="http://pulsedmedia.com/pulsedBox.air"
                                    title="pulsedBox :: Seedbox on your desktop">Download pulsedBox - Seedbox on your
                                    desktop (Current version 0.51)</a></li>
                            <li><a href="http://get.adobe.com/air/" title="Adobe AIR Installation package">Download
                                    Adobe AIR framework</a></li>
                        </ul>

                        <b>SFTP/FTP Client options</b>
                        <ul>
                            <li><a href="https://www.smartftp.com/get/SFTPMSI64.exe">Download SmartFTP (FTP access)</a>
                                (Allows multipart/multithreading transfers) - Shareware</li>
                            <li><a href="http://sourceforge.net/projects/filezilla/files/FileZilla_Client/3.14.0/FileZilla_3.14.0_win64-setup.exe/download?accel_key=57%3A1443615250%3Ahttps%253A//filezilla-project.org/download.php%253Ftype%253Dclient%3Aafc8cf93%248dd70e7a0baeb3db6c14e6bc35c69f8d0b775aa8&click_id=c1a9d4be-676c-11e5-a1a0-0200ac1d1d9f&source=accel"
                                    target="_blank">Filezilla - Popular opensource client</a></li>
                            <li><a href="http://www.bitkinex.com/ftp/client/bitkinex323.exe" target="_blank">Bitkinex -
                                    Popular All-In-One SFTP, FTP, WebDAV client. Freeware</a></li>
                        </ul>

                        <?php
                        if (
                            file_exists('/usr/local/bin/deluged') &&
                            file_exists('deluge.php')
                        ) {
                            ?>
                            <h6>Deluge</h6>
                            <p>Deluge default password: <b>pulsedDeluge</b> -- <b>Please change this immediately when
                                    accessing</p>
                            <?php
                            if (!file_exists('../.delugeEnable')) { // Not enabled, present enable button
                                ?>

                                <input type="button" name="delugeStart" value="Start Deluge"
                                    onClick="$.ajax({url: 'deluge.php?action=start', success: function() {
alert('Deluge starting, remember to change default password of pulsedDeluge to something else. Accessible at /deluge-USERNAME/. Refresh GUI to see tab'); location.reload(true) }});" />

                                <?php
                            } else {  // Enabled, present disable button + restart
                                ?>

                                <input type="button" name="delugeDisable" value="Disable Deluge"
                                    onClick="$.ajax({url: 'deluge.php?action=disable', success: function() { location.reload(true) }});" />
                                <input type="button" name="delugeRestart" value="Restart Deluge"
                                    onClick="$.ajax({url: 'deluge.php?action=restart', success: function() { alert('Deluge restart.'); }});" />
                                <?php
                            }

                        }



                        // RCLONE
                        if (
                            file_exists('/usr/bin/rclone') &&
                            file_exists('rclone.php')
                        ) {
                            ?>
                            <h6>Rclone web ui</h6>
                            <p>Rclone password is the same as your web access password</p>
                            <?php
                            if (!file_exists('../.rcloneEnable')) { // Not enabled, present enable button
                                ?>

                                <input type="button" name="rcloneStart" value="Start Rclone" onClick="$.ajax({url: 'rclone.php?action=start', success: function() {
   alert('Rclone starting, access at /user-USERNAME/rclone Refresh GUI to see tab'); location.reload(true) }});" />

                                <?php
                            } else {  // Enabled, present disable button + restart
                                ?>

                                <input type="button" name="rcloneDisable" value="Disable rclone"
                                    onClick="$.ajax({url: 'rclone.php?action=disable', success: function() { alert('Rclone disabled.'); location.reload(true) }});" />
                                <input type="button" name="rcloneRestart" value="Restart rclone"
                                    onClick="$.ajax({url: 'rclone.php?action=restart', success: function() { alert('Rclone restart.'); }});" />
                                <?php
                            }

                        }
                        //END RCLONE


                        // qBittorrent
                        if (
                            file_exists('/usr/bin/qbittorrent-nox') &&
                            file_exists('qbittorrent.php')
                        ) {
                            ?>
                            <h6>qBittorrent</h6>
                            <p>qBittorrent username is your own username and password is adminadmin by default. Change
                                password once logged in. If you get 503 it means lighttpd needs to be restarted most likely,
                                try that before contacting support - port has likely been changed.</p>
                            <?php
                            if (!file_exists('../.qbittorrentEnable')) { // Not enabled, present enable button
                                ?>

                                <input type="button" name="qbittorrentStart" value="Start qBittorrent"
                                    onClick="$.ajax({url: 'qbittorrent.php?action=start', success: function() {
   alert('qBittorrent starting, access at /user-USERNAME/qbittorrent/ -- Refresh GUI to see tab'); location.reload(true) }});" />

                                <?php
                            } else {  // Enabled, present disable button + restart
                                ?>

                                <input type="button" name="qbittorrentDisable" value="Disable qBittorrent"
                                    onClick="$.ajax({url: 'qbittorrent.php?action=disable', success: function() { alert('qBittorrent disabled.'); location.reload(true) }});" />
                                <input type="button" name="qbittorrentRestart" value="Restart qBittorrent"
                                    onClick="$.ajax({url: 'qbittorrent.php?action=restart', success: function() { alert('qBittorrent restart.'); }});" />
                                <?php
                            }

                        }
                        //END QBITTORRENT

                        ?>

                        <h6>rTorrent</h6>
                        <input type="button" name="rtorrentRestart" value="Restart rTorrent"
                            onClick="$.ajax({url: 'rtorrentRestart.php', success: function() { alert('rTorrent restart request input, please allow upto 2 minutes for restart to happen.'); }});" />

                        <?php
                        if (file_exists('lighttpdRestart.php')) {
                            ?>
                            <h6>Lighttpd</h6>
                            <input type="button" name="lighttpdRestart" value="Restart Lighttpd"
                                onClick="alert('Lighttpd restart might take couple of minutes'); $.ajax({url: 'lighttpdRestart.php?action=confirm-restart', success: function() {} });" />
                            <?php
                        }


                        if (!file_exists('owncloud') && 1 == 2) { // Disabled temporarily
                            ?>
                            <hr />
                            <h6>Owncloud ** Beta</h6>
                            <p><a href="setup-owncloud.php" target="_blank">Install owncloud by clicking here</a>. Refresh
                                this page after installation.</p>
                            <p><strong>Use default settings!</strong> install it to "owncloud" directory as suggested, do
                                not change any settings.</p>

                            <?php
                        } elseif (!file_exists("public/owncloud") && 1 == 2) {
                            $owner = @posix_getpwuid(fileowner(__FILE__));
                            $owner = @$owner['name'];
                            `ln -s ../owncloud public/owncloud`;
                            if (!empty($owner))
                                `ln -s ../../../../../data data/{$owner}/files/data`;
                        }

                        if (file_exists('openvpn-config.tgz')) {
                            ?>
                            <hr />
                            <h6>OpenVPN ** Beta</h6>
                            <p>OpenVPN Support has been added. You can download configuration below. Install OpenVPN from <a
                                    href="https://openvpn.net/vpn-client/" title="OpenVPN Packages">OpenVPN.net</a>.</p>
                            <p>You can open the tarball using WinRAR for example. Put the config files under OpenVPN config
                                dir, ie. <i>C:\Program Files\OpenVPN\config</i>. Login is the same as FTP/SFTP.</p>
                            <p><a href="openvpn-config.tgz" title="OpenVPN Configuration">OpenVPN Config Files</a>.</p>
                            <?
                        }

                        if ($vendor['pulsedBox'] == true) {
                            ?>
                            <hr />

                            <h6 style="color: red; font-weight: bold;">pulsedBox NEW alpha version</h6>
                            <p>We have converted the pulsedBox application to ElectronJS framework, this is still early
                                alpha but you can already test it. .torrent upload by opening via system does not work
                                currently, but drag'n'drop to rutorrent does work. Please let us know your feedback, bug
                                reports etc. by contacting support.</p>
                            <p><a href="http://pulsedmedia.com/pulsedBox-download/pulsedBox.exe">Download pulsedBox alpha
                                    version for Windows</a></p>


                            <h6>pulsedBox :: Seedbox on your desktop</h6>
                            <p>We have created an <a href="http://www.adobe.com/products/air/" target="_blank">Adobe AIR</a>
                                application to bring your seedbox on the Desktop! This makes a pulsed media seedbox to work
                                like it would be an desktop application, directly adding torrents from websites, folders on
                                your computer etc!
                                This software is still in early beta stages, with several known usage issues. Wrong login
                                credentials cause a blank page, and you have to manually associate .torrent files to the
                                application.</p>
                            <p>To install, first Download and run <a href="http://get.adobe.com/air/"
                                    title="Adobe AIR Installation package">Adobe AIR</a> package, then <a
                                    href="http://pulsedmedia.com/pulsedBox.air"
                                    title="pulsedBox :: Seedbox on your desktop">pulsedBox AIR</a> package.</p>
                            <p style="color: red;">There is an issue with the package due to certs, please check <a
                                    href="http://wiki.pulsedmedia.com/index.php/Installing_pulsedBox"
                                    title="Pulsed Media Wiki">wiki pulsedBox installation</a> information to install the
                                package.</p>

                            <?php
                        }
                        ?>

                        <h6>IRC - Internet Relay Chat</h6>
                        <p>You may come and chat with other Pulsed Media users and staff at IRC! Just click
                            <i>"Chat"</i> tab or login via SSH and type <i>"irssi"</i> which has been configured on most
                            servers to auto-join correct network and channel.
                        </p>
                        <p>Our IRC channel is #PulsedMedia on Freenode network.</p>

                    </div>
                    <div class="portfoliodesc">
                        <?php
                        echo quotaCreateSection($quotaInfo, $bonusQuota);
                        //echo bonusQuotaDisplay($bonusQuota);

                        if (@file_exists('../.trafficLimit')) {
                            $trafficLimit = (int) trim(@file_get_contents('../.trafficLimit'));

                            if (@file_exists('../.trafficData')) {
                                $trafficData = @unserialize(trim(@file_get_contents('../.trafficData')));
                                trafficCreateSection($trafficData, $trafficLimit);
                            } else {
                                $trafficLimit = number_format($trafficLimit);
                                echo "Traffic limit: {$trafficLimit} GiB<br />";
                            }
                        }

                        if (@file_exists('../.billingId')) {
                            $billingId = (int) @file_get_contents('../.billingId');
                            if ($billingId > 0) {
                                echo <<<EOF
                                <h6>Need more resources?</h6>
                                <p>Need more Diskspace, Traffic or Ram?</p>
                                <p>You can upgrade fast and easy, activation usually within few minutes! Just check out Your <a href="https://pulsedmedia.com/clients/upgrade.php?type=configoptions&id={$billingId}" target="_blank">Upgrade Options!</a></p>

EOF;
                            }

                        }
                        ?>

                        <h6>Announcements</h6>
                        <ul>

                            <?php


                            $rssFeed = new SimpleXMLElement('http://pulsedmedia.com/clients/announcementsrss.php', LIBXML_NOCDATA, true);
                            $rssFeed = json_decode(json_encode($rssFeed), true);  // Trick to turn into array
                            $rssFeed['channel']['item'] = array_slice($rssFeed['channel']['item'], 0, 4, true);	// Only 4
//var_dump($rssFeed);
//

                            foreach ($rssFeed['channel']['item'] as $thisItem) {
                                $thisItem['pubDate'] = date('d/m', strtotime($thisItem['pubDate']));
                                echo <<<EOF
<li>({$thisItem['pubDate']}) <a href="{$thisItem['link']}" target="_blank">{$thisItem['title']}</a></li>

EOF;

                            }



                            ?>

                        </ul>

                        <h6>Need support?</h6>
                        <ul>
                            <li><a href="http://pulsedmedia.com/clients/knowledgebase.php"
                                    title="Browse Pulsed Media Knowledgebase">Browse Knowledgebase</a></li>
                            <li><a href="http://wiki.pulsedmedia.com" title="Pulsed Media Wiki">Browse Wiki</a></li>
                            <li><a href="https://discord.gg/cGBz52HJtx" target="_blank"
                                    title="Join Pulsed Media on Discord">Discord</a>
                            <li>Technical: <a href="mailto:support@pulsedmedia.com"
                                    title="E-Mail Support">support@pulsedmedia.com</a></li>
                            <li>Billing: <a href="mailto:billing@pulsedmedia.com"
                                    title="E-Mail Billing">billing@pulsedmedia.com</a></li>
                        </ul>


                        <?php
                        /*  Following temporarily just commented out -- remove by end of 2022

                                            <h2>Restarting rTorrent</h2>
                                            <input type="button" name="rtorrentRestart" value="Restart rTorrent" onClick="$.ajax({url: 'rtorrentRestart.php', success: function() { alert('rTorrent restart request input, please allow upto 2 minutes for restart to happen.'); }});" />
                                            <p>Clicking this button will result in rTorrent being killed forcefully within a minute. After several minutes rTorrent will be automaticly restarted.</p>
                                            <p>If rTorrent is not running, please <b><i>do not</i></b> click this button. There is multiple levels of redundancy, but if you are certain it's not due to going over quota and rTorrent won't start within 20minutes, please contact support.</p>

                        */ ?>

                        <br />
                        <br />
                        <br />
                        <br />
                        <br /><br /><br /><br />

                    </div>
                </div>

            </div>
            <div class="full_bottom">
            </div>
        </div>
    </div><!--Wrap ends -->


    <script type="text/javascript">
        setTimeout(function () {
            location = ''
        }, 180000)
    </script>



</body>

</html>