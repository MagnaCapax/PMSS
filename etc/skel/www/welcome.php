<?php
/**
 * PMSS: User Frontend Welcome Page
 * This is the actual index / first page user sees with all the buttons etc.
 *
 * Original concept and implementation: Aleksi Ursin, circa 2010–2015.
 *
 * #TODO Major refactoring; https://github.com/MagnaCapax/PMSS/issues/64
 *
 * Copyright (C) 2010-2025 Magna Capax Finland Oy
 *
 * @author  Pulsed Media Dev Team
 * @package PMSS
 * @version 1.0
 */

if (file_exists('/scripts/lib/welcomeAnnouncements.php')) {
    require_once '/scripts/lib/welcomeAnnouncements.php';
}

$pageState = pmssWelcomePageStateBuild();
$quotaInfo = $pageState['quotaInfo'];
$bonusQuota = $pageState['bonusQuota'];
$bonusTraffic = $pageState['bonusTraffic'];
$vendor = $pageState['vendor'];
$contextualWelcomeMessage = $pageState['contextualWelcomeMessage'];
$delugePasswordHelpersAvailable = $pageState['delugePasswordHelpersAvailable'];
$delugePasswordNotice = $pageState['delugePasswordNotice'];
$delugePassword = $pageState['delugePassword'];
$welcomeHeadingHtml = pmssWelcomeHeadingHtmlBuild($contextualWelcomeMessage);
$announcementItemsHtml = pmssWelcomeAnnouncementItemsHtmlBuild();
$homeRaidNoticeHtml = pmssWelcomeHomeRaidNoticeHtmlRead();
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
    <style type="text/css">
        #pmss-action-notice {
            display: none;
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 9999;
            max-width: 580px;
            padding: 8px 12px;
            border: 1px solid #78a5d6;
            background-color: #edf4ff;
            color: #1a3d66;
            font-weight: bold;
        }
        #pmss-action-notice.pmss-error {
            border-color: #cc8f8f;
            background-color: #fff1f1;
            color: #7a1a1a;
        }
        .pmss-action-loading {
            margin-left: 6px;
            color: #666;
            font-size: 0.9em;
        }
        .pmss-raid-notice {
            margin: 12px 0 18px;
            padding: 12px 14px;
            border: 1px solid #d8a55a;
            background: #fff6e5;
            color: #5f3b00;
        }
        .pmss-raid-notice p {
            margin: 8px 0 0;
        }
        .pmss-raid-icon {
            color: #b22222;
            margin-right: 6px;
        }
        .pmss-raid-meta {
            margin-top: 8px;
            font-weight: bold;
        }
    </style>
    <script type="text/javascript">
        var pmssActionNoticeTimer = null;

        function pmssShowActionNotice(message, isError) {
            var notice = $('#pmss-action-notice');
            if (notice.length === 0) {
                alert(message);
                return;
            }

            notice.stop(true, true);
            notice.removeClass('pmss-error');
            if (isError) {
                notice.addClass('pmss-error');
            }

            notice.text(message).fadeIn('fast');

            if (pmssActionNoticeTimer !== null) {
                window.clearTimeout(pmssActionNoticeTimer);
            }

            pmssActionNoticeTimer = window.setTimeout(function() {
                notice.fadeOut('slow');
            }, 3200);
        }

        function pmssSetActionLoading(button, isLoading) {
            var actionButton = $(button);
            var indicator = actionButton.next('.pmss-action-loading');

            if (isLoading) {
                actionButton.attr('disabled', 'disabled');
                if (indicator.length === 0) {
                    indicator = $('<span class="pmss-action-loading" aria-live="polite">&#8987; Working...</span>');
                    actionButton.after(indicator);
                }
                indicator.show();
                return;
            }

            actionButton.removeAttr('disabled');
            if (indicator.length > 0) {
                indicator.hide();
            }
        }

        function pmssRunAction(button, url, successMessage, shouldReload, pendingMessage) {
            pmssSetActionLoading(button, true);

            if (pendingMessage) {
                pmssShowActionNotice(pendingMessage, false);
            }

            $.ajax({
                url: url,
                cache: false,
                success: function() {
                    pmssSetActionLoading(button, false);

                    if (successMessage) {
                        pmssShowActionNotice(successMessage, false);
                    }

                    if (shouldReload) {
                        window.setTimeout(function() {
                            location.reload(true);
                        }, 900);
                    }
                },
                error: function() {
                    pmssSetActionLoading(button, false);
                    pmssShowActionNotice('Action failed. Please try again in a moment.', true);
                }
            });
        }
    </script>

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
    <div id="pmss-action-notice" role="status" aria-live="polite"></div>
    <div id="wrap">
        <div id="full_page">
            <div class="full_top_nohd"><!-- top design --></div>
            <div class="full_body">
                <div class="portfoliobox">
                    <div class="portfolioimg">
                        <?php
                        echo $welcomeHeadingHtml;
                        echo $homeRaidNoticeHtml;
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
if ((file_exists('/usr/bin/deluged') || file_exists('/usr/local/bin/deluged')) && file_exists('deluge.php')) {
?>
                        <h6>Deluge</h6>
                        <p>Deluge password: <b><?php echo htmlspecialchars($delugePassword === '' ? 'Unavailable' : $delugePassword, ENT_QUOTES, 'UTF-8'); ?></b> (separate from your account password)</p>
<?php
    if ($delugePasswordHelpersAvailable) {
?>
                        <form method="post" action="">
                            <input type="hidden" name="delugePasswordRotate" value="1" />
                            <input type="submit" value="Rotate Deluge Password" />
                        </form>
<?php
    } else {
?>
                        <p><b>Deluge password rotation is unavailable on this host.</b></p>
<?php
    }

    if ($delugePasswordNotice !== '') {
?>
                        <p><b><?php echo htmlspecialchars($delugePasswordNotice, ENT_QUOTES, 'UTF-8'); ?></b></p>
<?php
    }

    if (!file_exists('../.delugeEnable')) {
?>
                        <input type="button" name="delugeStart" value="Start Deluge" onClick="pmssRunAction(this, 'deluge.php?action=start', 'Deluge starting. Accessible at /deluge-USERNAME/. Refresh GUI to see tab.', true, 'Deluge start request sent...');" />
<?php
    } else {
?>
                        <input type="button" name="delugeDisable" value="Disable Deluge" onClick="pmssRunAction(this, 'deluge.php?action=disable', 'Deluge disabled.', true, 'Disabling Deluge...');" />
                        <input type="button" name="delugeRestart" value="Restart Deluge" onClick="pmssRunAction(this, 'deluge.php?action=restart', 'Deluge restart requested.', false, 'Restarting Deluge...');" />
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
                        <input type="button" name="rcloneStart" value="Start Rclone" onClick="pmssRunAction(this, 'rclone.php?action=start', 'Rclone starting, access at /user-USERNAME/rclone. Refresh GUI to see tab.', true, 'Starting Rclone...');" />
<?php
    } else {
?>
                        <input type="button" name="rcloneDisable" value="Disable Rclone" onClick="pmssRunAction(this, 'rclone.php?action=disable', 'Rclone disabled.', true, 'Disabling Rclone...');" />
                        <input type="button" name="rcloneRestart" value="Restart Rclone" onClick="pmssRunAction(this, 'rclone.php?action=restart', 'Rclone restart requested.', false, 'Restarting Rclone...');" />
<?php
    }
}

if ((file_exists('/usr/bin/qbittorrent-nox') || file_exists('/usr/local/bin/qbittorrent-nox')) && file_exists('qbittorrent.php')) {
?>
                        <h6>qBittorrent</h6>
                        <p>qBittorrent username is your own username and password is <code>adminadmin</code> by default. Change password once logged in. If you get 503, try restarting Lighttpd — port may have changed.</p>
<?php
    if (!file_exists('../.qbittorrentEnable')) {
?>
                        <input type="button" name="qbittorrentStart" value="Start qBittorrent" onClick="pmssRunAction(this, 'qbittorrent.php?action=start', 'qBittorrent starting, access at /user-USERNAME/qbittorrent/ — Refresh GUI to see tab.', true, 'Starting qBittorrent...');" />
<?php
    } else {
?>
                        <input type="button" name="qbittorrentDisable" value="Disable qBittorrent" onClick="pmssRunAction(this, 'qbittorrent.php?action=disable', 'qBittorrent disabled.', true, 'Disabling qBittorrent...');" />
                        <input type="button" name="qbittorrentRestart" value="Restart qBittorrent" onClick="pmssRunAction(this, 'qbittorrent.php?action=restart', 'qBittorrent restart requested.', false, 'Restarting qBittorrent...');" />
<?php
    }
}
?>

                        <h6>rTorrent</h6>
                        <input type="button" name="rtorrentRestart" value="Restart rTorrent" onClick="pmssRunAction(this, 'rtorrentRestart.php', 'rTorrent restart request sent, please allow up to 2 minutes for restart to happen.', false, 'Sending rTorrent restart request...');" />
<?php
if (file_exists('lighttpdRestart.php')) {
?>
                        <h6>Lighttpd</h6>
                        <input type="button" name="lighttpdRestart" value="Restart Lighttpd" onClick="pmssRunAction(this, 'lighttpdRestart.php?action=confirm-restart', 'Lighttpd restart request sent. It might take a couple of minutes.', false, 'Lighttpd restart may take a couple of minutes...');" />
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
	                                $trafficIngress = null;
	                                if (@file_exists('../.trafficDataIngress')) {
                                    $trafficIngress = @unserialize(trim(@file_get_contents('../.trafficDataIngress')));
                                    if (!is_array($trafficIngress)) {
                                        $trafficIngress = null;
                                    }
	                                }
	                                trafficCreateSection($trafficData, $trafficLimit, $trafficIngress, $bonusTraffic);
	                            } else {
	                                if ($trafficLimit > 0) {
	                                    $effectiveLimit = $trafficLimit + max(0, (int) $bonusTraffic);
	                                    $trafficLimitText = number_format($effectiveLimit) . ' GiB';
	                                    if ($bonusTraffic > 0) {
	                                        $trafficLimitText .= ' (Bonus traffic: ' . number_format($bonusTraffic) . ' GiB)';
	                                    }
	                                } else {
	                                    $trafficLimitText = 'Unlimited';
	                                }
	                                echo "Traffic limit: {$trafficLimitText}<br />";
	                            }
	                        }

                        echo memoryCreateSection();

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
echo $announcementItemsHtml;
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
/**
 * Gather the state used by the welcome page before rendering begins.
 *
 * @return array<string,mixed>
 */
function pmssWelcomePageStateBuild() {
    $quotaInfo = pmssWelcomeQuotaInfoRead();
    $delugeState = pmssWelcomeDelugeStateBuild('../.config/deluge/auth');

    return array(
        'quotaInfo' => $quotaInfo,
        'bonusQuota' => pmssWelcomeIntegerFileRead('../.bonusQuota'),
        'bonusTraffic' => pmssWelcomeIntegerFileRead('../.bonusTraffic', true),
        'vendor' => pmssWelcomeVendorRead(),
        'contextualWelcomeMessage' => pmssWelcomeContextualMessageBuild($quotaInfo),
        'delugePasswordHelpersAvailable' => $delugeState['helpersAvailable'],
        'delugePasswordNotice' => $delugeState['passwordNotice'],
        'delugePassword' => $delugeState['password'],
    );
}

function pmssWelcomeQuotaInfoRead() {
    if (!isset($_GET['quota'])) {
        return array();
    }

    $quotaInfo = urldecode($_GET['quota']);
    $quotaInfo = str_replace('\\', '', $quotaInfo); // Serialized data might be malformed with \ chars!
    $quotaInfo = @unserialize($quotaInfo);

    return is_array($quotaInfo) ? $quotaInfo : array();
}

function pmssWelcomeIntegerFileRead($path, $clampNegativeToZero = false) {
    if (!file_exists($path)) {
        return 0;
    }

    $value = (int) @file_get_contents($path);
    if ($clampNegativeToZero && $value < 0) {
        return 0;
    }

    return $value;
}

function pmssWelcomeVendorRead() {
    $vendorDefault = array(
        'name'      => 'Pulsed Media',
        'pulsedBox' => true
    );

    if (!file_exists('/etc/seedbox/config/vendor')) {
        return $vendorDefault;
    }

    $vendor = @file_get_contents('/etc/seedbox/config/vendor');
    $vendor = @unserialize($vendor);
    if (!is_array($vendor) || count($vendor) == 0 || !isset($vendor['name']) || empty($vendor['name'])) {
        return $vendorDefault;
    }

    return $vendor;
}

function pmssWelcomeContextualMessageBuild($quotaInfo) {
    if (!file_exists('/scripts/lib/welcomeMessage.php')) {
        return '';
    }

    require_once '/scripts/lib/welcomeMessage.php';
    if (!function_exists('pmssWelcomeMessageForUser')) {
        return '';
    }

    $userHome = @realpath(dirname(__DIR__));
    if (!is_string($userHome) || $userHome === '') {
        $userHome = dirname(__DIR__);
    }

    $username = basename($userHome);
    if (!is_string($username) || $username === '' || $username === '.' || $username === '..') {
        $username = (string) @get_current_user();
    }

    return pmssWelcomeMessageForUser($quotaInfo, $userHome, $username);
}

function pmssWelcomeHomeRaidNoticeHtmlRead() {
    if (!file_exists('/scripts/lib/storageHealth.php')) {
        return '';
    }

    require_once '/scripts/lib/storageHealth.php';
    if (!function_exists('pmssStorageHealthHomeRaidActivity')) {
        return '';
    }

    $activity = pmssStorageHealthHomeRaidActivity();
    if (!function_exists('pmssStorageHealthHomeRaidNoticeHtmlBuild')) {
        return '';
    }

    return pmssStorageHealthHomeRaidNoticeHtmlBuild($activity);
}

function pmssWelcomeDelugeStateBuild($delugeAuthPath) {
    if (file_exists('/scripts/lib/user/passwords.php')) {
        require_once '/scripts/lib/user/passwords.php';
    }

    $helpersAvailable = function_exists('pmssDelugeAuthReadLocalclientPassword')
        && function_exists('pmssDelugeAuthWriteLocalclientPassword')
        && function_exists('pmssDelugeServicePasswordGenerate');
    $passwordNotice = '';
    $password = '';

    if ($helpersAvailable && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delugePasswordRotate'])) {
        $newDelugePassword = pmssDelugeServicePasswordGenerate();
        $passwordUpdated = pmssDelugeAuthWriteLocalclientPassword($delugeAuthPath, $newDelugePassword);
        $passwordNotice = $passwordUpdated
            ? 'Deluge password rotated. Re-login in Deluge Web UI with the new password below.'
            : 'Deluge password rotation failed. Please try again.';
    }

    if ($helpersAvailable) {
        $password = pmssDelugeAuthReadLocalclientPassword($delugeAuthPath);
    }

    return array(
        'helpersAvailable' => $helpersAvailable,
        'passwordNotice' => $passwordNotice,
        'password' => $password,
    );
}

function pmssWelcomeHeadingHtmlBuild($contextualWelcomeMessage) {
    $html = '';
    $welcomeHeading = pmssWelcomeRemoteFetch('https://pulsedmedia.com/remote/welcomeHeadingText.php');
    if ($welcomeHeading !== false) {
        $html .= $welcomeHeading;
    }

    if (file_exists('/etc/seedbox/config/vendorWelcome')) {
        $html .= (string) @file_get_contents('/etc/seedbox/config/vendorWelcome');
    }

    if (!empty($contextualWelcomeMessage)) {
        $html .= $contextualWelcomeMessage;
    }

    return $html;
}

function pmssWelcomeAnnouncementItemsHtmlBuild() {
    $rssRaw = pmssWelcomeRemoteFetch('https://pulsedmedia.com/clients/announcementsrss.php');
    if ($rssRaw === false || !function_exists('pmssWelcomeAnnouncementItemsHtmlBuildFromRaw')) {
        return '';
    }

    return pmssWelcomeAnnouncementItemsHtmlBuildFromRaw($rssRaw);
}

function pmssWelcomeRemoteFetch($url) {
    return @file_get_contents($url, false, pmssWelcomeHttpContextCreate());
}

function pmssWelcomeHttpContextCreate() {
    return stream_context_create(array(
        'http' => array(
            'timeout'    => 5,
            'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)'
        )
    ));
}

function bonusQuotaDisplay($bonusQuota) {
    if ($bonusQuota != 0) {
        return '<b>BONUS QUOTA:</b> ' . number_format($bonusQuota) . ' GiB<br />';
    }
    return '';
}

function readUserRamLimitBytes() {
    $configPath = '../.config/pmss-user.json';
    if (!is_file($configPath) || is_link($configPath)) {
        return null;
    }

    $raw = @file_get_contents($configPath);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $userConfig = json_decode($raw, true);
    if (!is_array($userConfig) || !isset($userConfig['ramMiB']) || !is_numeric($userConfig['ramMiB'])) {
        return null;
    }

    $ramMiB = (float) $userConfig['ramMiB'];
    if ($ramMiB <= 0) {
        return null;
    }

    return $ramMiB * 1024 * 1024;
}

function readUserMemoryCurrentBytes() {
    $resourcePath = '../.resourceData';
    if (!is_file($resourcePath) || is_link($resourcePath)) {
        return null;
    }

    $raw = @file_get_contents($resourcePath);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $resourceData = @unserialize($raw);
    if (!is_array($resourceData)
        || !isset($resourceData['memory'])
        || !is_array($resourceData['memory'])
        || !isset($resourceData['memory']['current'])
        || !is_numeric($resourceData['memory']['current'])) {
        return null;
    }

    return (float) $resourceData['memory']['current'];
}

function readSystemdMemoryCurrentBytes() {
    if (!function_exists('shell_exec')) {
        return null;
    }

    $memoryCurrent = @shell_exec("systemctl show user-$('/usr/bin/id' -u).slice -p MemoryCurrent --value 2>/dev/null");
    if (!is_string($memoryCurrent)) {
        return null;
    }

    $memoryCurrent = trim($memoryCurrent);
    if ($memoryCurrent === '' || !is_numeric($memoryCurrent)) {
        return null;
    }

    return (float) $memoryCurrent;
}

function memoryCreateSection() {
    $currentBytes = readUserMemoryCurrentBytes();
    if ($currentBytes === null) {
        $currentBytes = readSystemdMemoryCurrentBytes();
    }

    $limitBytes = readUserRamLimitBytes();

    if ($currentBytes === null && $limitBytes === null) {
        return '<h6>RAM Info</h6><b>RAM usage data is unavailable right now.</b><hr />';
    }

    $currentText = ($currentBytes === null)
        ? 'n/a'
        : filesize2HumanReadable($currentBytes);

    if ($limitBytes === null || $limitBytes <= 0) {
        return <<<EOF
<h6>RAM Info</h6>
Current RAM usage: {$currentText}<br />
RAM limit: n/a
<hr />
EOF;
    }

    $limitText = filesize2HumanReadable($limitBytes);

    if ($currentBytes === null) {
        return <<<EOF
<h6>RAM Info</h6>
Current RAM usage: n/a<br />
RAM limit: {$limitText}
<hr />
EOF;
    }

    $percent = round(($currentBytes / $limitBytes) * 100, 1);
    if (!is_finite($percent)) {
        $percent = 0;
    }

    $titleText = "{$currentText} / {$limitText}";
    $gauge = createGauge($titleText, $titleText, $percent);

    if ($percent > 100) {
        $warning = '<br /><b style="color: red;">RAM LIMIT EXCEEDED</b><br />Processes may be killed (OOM) until memory usage drops.<br />';
    } elseif ($percent >= 80) {
        $warning = '<br /><b style="color: #d2691e;">RAM WARNING</b><br />You are close to your RAM limit. Consider reducing running services or upgrading your plan.<br />';
    } else {
        $warning = '';
    }

    return <<<EOF
<h6>RAM Info</h6>
{$gauge}
{$warning}
<hr />
EOF;
}

	function trafficCreateSection($trafficData, $trafficLimit, $trafficIngress = null, $bonusTraffic = 0) {
	    if (count($trafficData) == 0) return;

	    $trafficUsed = round($trafficData['raw']['month']);
	    $ratioGoodMin = 2.0;
	    $ratioWarnMin = 1.0;
	    $inboundLine = '';
	    $ratioLine = '';
	    $outboundMonth = null;
	    $inboundMonth = null;
	    if (isset($trafficData['raw']['month']) && is_numeric($trafficData['raw']['month'])) {
	        $outboundMonth = (float) $trafficData['raw']['month'];
	    }
	    if (is_array($trafficIngress) && isset($trafficIngress['raw']['month']) && is_numeric($trafficIngress['raw']['month'])) {
	        $inboundMonth = (float) $trafficIngress['raw']['month'];
	        $inboundUsed = round($inboundMonth / 1024) . " GiB";
	        $inboundLine = '<br />Inbound (30 days): '.$inboundUsed;
	    }
	    if ($inboundMonth !== null && $outboundMonth !== null) {
	        if ($outboundMonth > 0) {
	            $ratio = $inboundMonth / $outboundMonth;
	            $ratioText = number_format($ratio, 2) . ':1';
	            if ($ratio >= $ratioGoodMin) {
	                $ratioColor = '#81c784';
	            } elseif ($ratio >= $ratioWarnMin) {
	                $ratioColor = '#ffb74d';
	            } else {
	                $ratioColor = '#ef5350';
	            }
	        } else {
	            $ratioText = 'N/A';
	            $ratioColor = '#b0bec5';
	        }
	        $ratioLine = '<br />Inbound:Outbound ratio (30 days): <span style="color: '.$ratioColor.'">'.$ratioText.'</span>';
	    }
	    if ($trafficLimit <= 0) {
	        $trafficUsed = round($trafficUsed / 1024) . " GiB";

	        echo <<<EOF
	    <h6>Traffic Info</h6>
	    Traffic used (30 days): {$trafficUsed}<br />
	    Traffic limit: Unlimited{$inboundLine}{$ratioLine}<br />
	    This is rolling past 30 days, <a href="http://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/" target="_blank">read more</a>.
	    <hr />
	EOF;
	        return;
	    }
	    $bonusTraffic = (int) $bonusTraffic;
	    if ($bonusTraffic < 0) {
	        $bonusTraffic = 0;
	    }
	    $limitTotal = $trafficLimit + $bonusTraffic;
	    $percent = ($limitTotal > 0) ? round((($trafficUsed / 1024) / $limitTotal) * 100) : 0;
	    if (!is_finite($percent)) $percent = 0;
	    $trafficUsed = round($trafficUsed / 1024) . " GiB";

	    if ($percent > 100) {
	        $warning = '<br /><b style="color: red;">OVER TRAFFIC LIMIT WARNING - REDUCED BANDWIDTH</b><br />You are beyond your traffic limit. Consider upgrading your plan or adding extra traffic.<br />Datacenter external outbound (TO internet) bandwidth limited to 100 Mbps. Datacenter internal and inbound bandwidth is unrestricted.';
    } else {
        $warning = '';
    }

    $titleText = "{$trafficUsed} / {$limitTotal} GiB";
    $bonusLine = ($bonusTraffic > 0) ? '<br />Bonus traffic: ' . number_format($bonusTraffic) . ' GiB' : '';
    $gauge = createGauge($titleText, $titleText . $bonusLine, $percent);

    echo <<<EOF
    <h6>Traffic Info</h6>
    {$gauge}
    {$warning}
    {$inboundLine}{$ratioLine}
    This is rolling past 30 days, <a href="http://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/" target="_blank">read more</a>.
    <hr />
EOF;
}

function createGauge($titleText, $footerText, $percent, $percentMax = 0) {
    if (!is_finite($percent)) $percent = 0;
    if (!is_finite($percentMax)) $percentMax = 0;
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
    if (!is_finite($percent)) $percent = 0;
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

    $percent = ($totalSpace > 0) ? round(($usedBytes / $totalSpace) * 100, 1) : 0;
    $percentFromBurst = ($hardLimit > 0) ? round(($usedBytes / $hardLimit) * 100) : 0;
    if ($percent < 100 && $totalSpace > 0) {
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
