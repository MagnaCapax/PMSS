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

// Customer-side helpers MUST live in the customer tree (etc/skel/www/) because
// per-user lighttpd runs as the customer UID and cannot traverse /scripts/
// (intentionally 750 root:root — the operator-only security boundary).
foreach ([
    'welcomeAnnouncements.php',
    'webCgroupMemoryStatus.php',
    'userMediaStackPanel.php',
    'userTrafficLimit.php',
] as $pmssWelcomeHelper) {
    $pmssWelcomeHelperPath = __DIR__.'/'.$pmssWelcomeHelper;
    if (file_exists($pmssWelcomeHelperPath)) {
        require_once $pmssWelcomeHelperPath;
    }
}
// /scripts/lib/traffic/storage.php require removed 2026-05-17: dead code
// — no function from traffic/storage.php was called from customer PHP.
// pmssTrafficLimitStateRead (the only function welcome.php / stats.php use)
// lives in customer-readable userTrafficLimit.php (see ADR 0016).


$pageState = pmssWelcomePageStateBuild();
$quotaInfo = $pageState['quotaInfo'];
$bonusQuota = $pageState['bonusQuota'];
$trafficLimitState = $pageState['trafficLimitState'];
$bonusTraffic = $trafficLimitState['bonusGiB'];
$vendor = $pageState['vendor'];
$contextualWelcomeMessage = $pageState['contextualWelcomeMessage'];
$delugePasswordCanRotate = $pageState['delugePasswordCanRotate'];
$delugePasswordNotice = $pageState['delugePasswordNotice'];
$delugePassword = $pageState['delugePassword'];
$mediaStackStatus = $pageState['mediaStackStatus'];
$billingId = $pageState['billingId'];
$trafficBandwidthState = $pageState['trafficBandwidthState'];
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
    <link href="screen.css" rel="stylesheet" type="text/css" media="screen" />
    <!-- Javascript -->
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
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
        .pmss-media-stack-box {
            margin: 10px 0 14px;
            padding: 12px 14px;
            border: 1px solid #78a5d6;
            background: #edf4ff;
            color: #1a3d66;
        }
        .pmss-media-stack-state-installed {
            border-color: #6aaf71;
            background: #edf9ef;
            color: #234f27;
        }
        .pmss-media-stack-state-failed,
        .pmss-media-stack-state-blocked {
            border-color: #cc8f8f;
            background: #fff1f1;
            color: #7a1a1a;
        }
        .pmss-media-stack-box ul {
            margin: 8px 0 0 18px;
        }
        .pmss-media-stack-box pre {
            margin-top: 10px;
            max-height: 220px;
            overflow: auto;
            padding: 10px;
            background: #fff;
            border: 1px solid #c8d7eb;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .pmss-raid-notice {
            margin: 12px 0 18px;
            padding: 12px 14px;
            border: 1px solid #d8a55a;
            background: #fff6e5;
            color: #5f3b00;
        }
        .pmss-raid-notice-error {
            border-color: #cc8f8f;
            background: #fff1f1;
            color: #7a1a1a;
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
        var pmssMediaStackPollTimer = null;

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

        function pmssMediaStackPollSchedule(delay) {
            if (pmssMediaStackPollTimer !== null) {
                window.clearTimeout(pmssMediaStackPollTimer);
                pmssMediaStackPollTimer = null;
            }

            if (delay > 0) {
                pmssMediaStackPollTimer = window.setTimeout(pmssMediaStackStatusRefresh, delay);
            }
        }

        function pmssMediaStackApply(payload) {
            var panel = $('#pmss-media-stack-status');
            var button = $('#pmss-media-stack-start');

            if (panel.length > 0 && payload && payload.html) {
                panel.html(payload.html);
            }

            if (button.length > 0 && payload) {
                if (payload.canStart) {
                    button.removeAttr('disabled');
                } else {
                    button.attr('disabled', 'disabled');
                }
            }

            pmssMediaStackPollSchedule(payload && payload.poll ? 4000 : 0);
        }

        function pmssMediaStackStatusRefresh() {
            if ($('#pmss-media-stack-status').length === 0) {
                pmssMediaStackPollSchedule(0);
                return;
            }

            $.ajax({
                url: 'mediaStack.php?action=status',
                dataType: 'json',
                cache: false,
                success: function(payload) {
                    pmssMediaStackApply(payload);
                }
            });
        }

        function pmssMediaStackStart(button) {
            pmssSetActionLoading(button, true);
            pmssShowActionNotice('Starting media stack install...', false);

            $.ajax({
                url: 'mediaStack.php?action=start',
                type: 'POST',
                dataType: 'json',
                cache: false,
                success: function(payload) {
                    pmssSetActionLoading(button, false);
                    pmssMediaStackApply(payload);
                    if (payload && payload.message) {
                        pmssShowActionNotice(payload.message, false);
                    }
                },
                error: function(xhr) {
                    pmssSetActionLoading(button, false);
                    if (xhr && xhr.responseText) {
                        pmssMediaStackStatusRefresh();
                    }
                    pmssShowActionNotice('Media stack install could not be started from the panel.', true);
                }
            });
        }
    </script>

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
                            <li><a href="https://www.putty.org/" target="_blank">Download PuTTY (SSH access)</a></li>
                            <li><a href="https://winscp.net/eng/download.php" target="_blank">Download WinSCP (SFTP/SCP access)</a></li>
                        </ul>
                        <b>SFTP/FTP Client options</b>
                        <ul>
                            <li><a href="https://filezilla-project.org/download.php?platform=win64" target="_blank">FileZilla - Popular opensource client</a></li>
                        </ul>

<?php
if ((file_exists('/usr/bin/deluged') || file_exists('/usr/local/bin/deluged')) && file_exists('deluge.php')) {
?>
                        <h6>Deluge</h6>
                        <p>Deluge Web UI password: <b><?php echo htmlspecialchars($delugePassword === '' ? 'Unavailable' : $delugePassword, ENT_QUOTES, 'UTF-8'); ?></b> (also used for the daemon connection; separate from your account password)</p>
<?php
    if ($delugePasswordCanRotate) {
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
                        <p>qBittorrent username is your own username and password matches your account password. Change it once logged in if you want a separate WebUI password. If you get 503, try restarting Lighttpd — port may have changed.</p>
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

<?php
if (file_exists('mediaStack.php') && function_exists('pmssMediaStackPanelHtmlBuild')) {
?>
                        <h6>Media Stack</h6>
                        <div id="pmss-media-stack-status"><?php echo pmssMediaStackPanelHtmlBuild($mediaStackStatus); ?></div>
                        <input type="button" id="pmss-media-stack-start" name="mediaStackStart" value="Install Media Stack" onClick="pmssMediaStackStart(this);"<?php if (empty($mediaStackStatus['canStart'])) echo ' disabled="disabled"'; ?> />
<?php
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
?>

                    </div>

                    <div class="portfoliodesc">
                        <?php
                        echo quotaCreateSection($quotaInfo, $bonusQuota);

                        if (@file_exists('../.trafficLimit')) {
                            $trafficLimit = (int) $trafficLimitState['limitGiB'];
                            $trafficData = null;
                            if (is_file('../.trafficData') && !is_link('../.trafficData') && function_exists('pmssReadSerializedArrayFile')) {
                                $trafficData = pmssReadSerializedArrayFile('../.trafficData');
                            }
                            if (is_array($trafficData)) {
                                $trafficIngress = null;
                                if (is_file('../.trafficDataIngress') && !is_link('../.trafficDataIngress') && function_exists('pmssReadSerializedArrayFile')) {
                                    $trafficIngress = pmssReadSerializedArrayFile('../.trafficDataIngress');
                                }
                                trafficCreateSection($trafficData, $trafficLimit, $trafficIngress, $bonusTraffic, $trafficBandwidthState, $billingId);
                            } else {
                                if ($trafficLimit > 0) {
                                    $effectiveLimit = (int) $trafficLimitState['effectiveLimitGiB'];
                                    $trafficLimitText = number_format($effectiveLimit) . ' GiB';
                                    if ($bonusTraffic > 0) {
                                        $trafficLimitText .= ' (Bonus traffic: ' . number_format($bonusTraffic) . ' GiB)';
                                    }
                                } else {
                                    $trafficLimitText = 'Unlimited';
                                }
                                echo "Traffic limit: {$trafficLimitText}<br />";
                                echo pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingId).'<br />';
                            }
                        }

                        echo memoryCreateSection();

                        if ($billingId > 0) {
                            echo <<<EOF
                            <h6>Need more resources?</h6>
                    <p>Need more disk space, traffic, or RAM?</p>
                            <p>You can upgrade fast and easy — activation usually within few minutes! Just check out your <a href="https://pulsedmedia.com/clients/upgrade.php?type=configoptions&id={$billingId}" target="_blank">Upgrade Options!</a></p>
EOF;
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
                            <li><a href="https://wiki.pulsedmedia.com" title="Pulsed Media Wiki">Browse Wiki</a></li>
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
        pmssMediaStackStatusRefresh();
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
    $home = dirname(__DIR__);
    $delugeState = pmssWelcomeDelugeStateBuild(basename($home), $home.'/.config/deluge/auth');
    $mediaStackStatus = array(
        'state' => 'blocked',
        'message' => 'Media stack panel helper is unavailable on this host.',
        'details' => array(),
        'tail' => '',
        'urls' => array(),
        'canStart' => false,
        'poll' => false,
    );

    if (function_exists('pmssMediaStackPanelStatusRead')
        && function_exists('pmssMediaStackPanelCurrentUserRead')
        && function_exists('pmssMediaStackPanelCurrentHostnameRead')) {
        $mediaStackStatus = pmssMediaStackPanelStatusRead(
            $home,
            pmssMediaStackPanelCurrentUserRead($home),
            pmssMediaStackPanelCurrentHostnameRead()
        );
    }

    $trafficLimitState = function_exists('pmssTrafficLimitStateRead')
        ? pmssTrafficLimitStateRead('../.trafficLimit', '../.bonusTraffic')
        : array('limitGiB' => 0, 'bonusGiB' => 0, 'effectiveLimitGiB' => 0);
    $billingId = pmssWelcomeBillingIdRead('../.billingId');
    $trafficBandwidthState = pmssWelcomeTrafficBandwidthStateBuild('../.throttle');

    return array(
        'quotaInfo' => $quotaInfo,
        'bonusQuota' => (int) @file_get_contents('../.bonusQuota'),
        'trafficLimitState' => $trafficLimitState,
        'vendor' => pmssWelcomeVendorRead(),
        'contextualWelcomeMessage' => pmssWelcomeContextualMessageBuild($quotaInfo),
        'delugePasswordCanRotate' => $delugeState['canRotate'],
        'delugePasswordNotice' => $delugeState['passwordNotice'],
        'delugePassword' => $delugeState['password'],
        'mediaStackStatus' => $mediaStackStatus,
        'billingId' => $billingId,
        'trafficBandwidthState' => $trafficBandwidthState,
    );
}

/**
 * Read billing profile ID for upgrade links.
 */
function pmssWelcomeBillingIdRead($path) {
    if (!file_exists($path)) {
        return 0;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return 0;
    }

    $billingId = (int) trim($raw);
    return $billingId > 0 ? $billingId : 0;
}

/**
 * Resolve baseline and currently effective per-account bandwidth caps.
 *
 * @return array{defaultCapMbit:int,effectiveCapMbit:int,isReduced:bool}
 */
function pmssWelcomeTrafficBandwidthStateBuild($throttlePath) {
    $defaultCapMbit = pmssWelcomeTrafficDefaultCapMbitRead();
    $effectiveCapMbit = $defaultCapMbit;

    if (file_exists($throttlePath)) {
        $rawThrottle = @file_get_contents($throttlePath);
        if (is_string($rawThrottle)) {
            $rawThrottle = trim($rawThrottle);
            if ($rawThrottle !== '' && ctype_digit($rawThrottle)) {
                $parsedCap = (int) $rawThrottle;
                if ($parsedCap > 0) {
                    $effectiveCapMbit = $parsedCap;
                }
            }
        }
    }

    return array(
        'defaultCapMbit' => $defaultCapMbit,
        'effectiveCapMbit' => $effectiveCapMbit,
        'isReduced' => $effectiveCapMbit < $defaultCapMbit,
    );
}

/**
 * Read the default post-limit cap with per-user override compatibility.
 */
function pmssWelcomeTrafficDefaultCapMbitRead() {
    $defaultCapMbit = 100;
    if (file_exists($path = '/etc/seedbox/config/network')) {
        $networkConfig = @include $path;
        if (is_array($networkConfig)
            && isset($networkConfig['throttle'])
            && is_array($networkConfig['throttle'])
            && isset($networkConfig['throttle']['max'])
            && is_numeric($networkConfig['throttle']['max'])) {
            $defaultCapMbit = (int) $networkConfig['throttle']['max'];
        }
    }
    if ($defaultCapMbit <= 0) {
        $defaultCapMbit = 100;
    }

    $userConfigPath = '../.config/pmss-user.json';
    if (file_exists($userConfigPath)) {
        $userConfigRaw = @file_get_contents($userConfigPath);
        if (is_string($userConfigRaw) && trim($userConfigRaw) !== '') {
            $userConfig = json_decode($userConfigRaw, true);
            if (is_array($userConfig)
                && isset($userConfig['trafficCapMbit'])
                && is_numeric($userConfig['trafficCapMbit'])) {
                $userCapMbit = (int) $userConfig['trafficCapMbit'];
                if ($userCapMbit > 0) {
                    $defaultCapMbit = $userCapMbit;
                }
            }
        }
    }

    return $defaultCapMbit;
}

/**
 * Build tiny traffic-cap disclosure text shown near usage gauge.
 */
function pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingId) {
    $effectiveCapMbit = isset($trafficBandwidthState['effectiveCapMbit']) && is_numeric($trafficBandwidthState['effectiveCapMbit'])
        ? (int) $trafficBandwidthState['effectiveCapMbit']
        : 0;
    $isReduced = isset($trafficBandwidthState['isReduced']) && $trafficBandwidthState['isReduced'] === true;
    if (!$isReduced) {
        return '<span style="font-size: 0.82em; color: #666;">Current effective: full plan port speed</span>';
    }

    $upgradeUrl = 'https://pulsedmedia.com/clients/upgrade.php?type=configoptions';
    if ((int) $billingId > 0) {
        $upgradeUrl .= '&id='.(int) $billingId;
    }

    $effectiveText = number_format(max(0, $effectiveCapMbit));

    return '<span style="font-size: 0.82em; color: #7a1a1a;">Current effective: '.$effectiveText.' Mbps (reduced)</span>'
        . '<br /><span style="font-size: 0.82em;"><a href="'.htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8').'" target="_blank">Need more bandwidth? Upgrade your plan.</a></span>';
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

function pmssWelcomeVendorRead() {
    $vendorDefault = array(
        'name' => 'Pulsed Media'
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
    $pmssWelcomeMessageLib = __DIR__.'/welcomeMessage.php';
    if (!file_exists($pmssWelcomeMessageLib)) {
        return '';
    }

    require_once $pmssWelcomeMessageLib;
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
    // Customer-side storage-health notice (see storageHealthNotice.php).
    $pmssStorageHealthNoticeLib = __DIR__.'/storageHealthNotice.php';
    if (!file_exists($pmssStorageHealthNoticeLib)) {
        return '';
    }

    require_once $pmssStorageHealthNoticeLib;
    if (!function_exists('pmssStorageHealthHomeRaidActivity')) {
        return '';
    }

    $activity = pmssStorageHealthHomeRaidActivity();
    if (!function_exists('pmssStorageHealthHomeRaidNoticeHtmlBuild')) {
        return '';
    }

    return pmssStorageHealthHomeRaidNoticeHtmlBuild($activity);
}

function pmssWelcomeDelugeStateBuild($username, $delugeAuthPath) {
    // Customer-side password display: see userPasswords.php (ADR 0016).
    // Rotation is allowed only when the customer-tree helper defines it
    // locally; customer PHP must not call helpers defined only in /scripts/lib.
    $pmssUserPasswordsLib = __DIR__.'/userPasswords.php';
    if (file_exists($pmssUserPasswordsLib)) {
        require_once $pmssUserPasswordsLib;
    }

    $canRead = function_exists('pmssDelugeAuthReadLocalclientPassword');
    $canRotate = function_exists('pmssDelugeServicePasswordRotate');
    $passwordNotice = '';
    $password = '';

    if ($canRotate && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delugePasswordRotate'])) {
        $newDelugePassword = pmssDelugeServicePasswordRotate((string) $username);
        $passwordNotice = $newDelugePassword !== ''
            ? 'Deluge password rotated. Re-login in Deluge Web UI with the new password below.'
            : 'Deluge password rotation failed. Please try again.';
    }

    if ($canRead) {
        $password = pmssDelugeAuthReadLocalclientPassword($delugeAuthPath);
    }

    return array(
        'canRotate' => $canRotate,
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
    return function_exists('pmssWelcomeHttpContextCreate')
        ? @file_get_contents($url, false, pmssWelcomeHttpContextCreate())
        : false;
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
    $resourceData = readUserResourceData();
    if (!is_array($resourceData)
        || !isset($resourceData['memory'])
        || !is_array($resourceData['memory'])
        || !isset($resourceData['memory']['current'])
        || !is_numeric($resourceData['memory']['current'])) {
        return null;
    }

    return (float) $resourceData['memory']['current'];
}

function readUserResourceData() {
    if (!function_exists('pmssReadSerializedArrayFile')) {
        return null;
    }
    return pmssReadSerializedArrayFile('../.resourceData');
}

function readUserMemoryBreakdownBytes() {
    $resourceData = readUserResourceData();
    if (!is_array($resourceData)
        || !isset($resourceData['memory'])
        || !is_array($resourceData['memory'])) {
        return array();
    }

    $memory = $resourceData['memory'];
    $breakdown = array();
    if (isset($memory['anon']) && is_numeric($memory['anon'])) {
        $breakdown['anon'] = (float) $memory['anon'];
    }
    if (isset($memory['file']) && is_numeric($memory['file'])) {
        $breakdown['file'] = (float) $memory['file'];
    }

    return $breakdown;
}

function readSystemdMemoryCurrentBytes() {
    if (!function_exists('pmssFrontendShellExecAvailable') || !pmssFrontendShellExecAvailable()) {
        return null;
    }

    $memoryCurrent = @pmssFrontendShellExec("systemctl show user-$('/usr/bin/id' -u).slice -p MemoryCurrent --value 2>/dev/null");
    if (!is_string($memoryCurrent)) {
        return null;
    }

    $memoryCurrent = trim($memoryCurrent);
    if ($memoryCurrent === '' || !is_numeric($memoryCurrent)) {
        return null;
    }

    return (float) $memoryCurrent;
}

function readSystemdMemoryBreakdownBytes() {
    $uid = function_exists('posix_getuid') ? (int) posix_getuid() : null;
    if ($uid === null && function_exists('pmssFrontendShellExecAvailable') && pmssFrontendShellExecAvailable()) {
        $uidRaw = @pmssFrontendShellExec('/usr/bin/id -u 2>/dev/null');
        if (is_string($uidRaw) && ctype_digit(trim($uidRaw))) {
            $uid = (int) trim($uidRaw);
        }
    }
    if (!is_int($uid) || $uid < 0) {
        return array();
    }

    foreach (array(
        '/sys/fs/cgroup/user.slice/user-'.$uid.'.slice/memory.stat',
        '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice/memory.stat',
    ) as $path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }

        $breakdown = array();
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (count($parts = preg_split('/\s+/', trim($line), 2)) !== 2 || !ctype_digit($parts[1])) {
                continue;
            }
            if ($parts[0] === 'anon') {
                $breakdown['anon'] = (float) $parts[1];
            } elseif ($parts[0] === 'file') {
                $breakdown['file'] = (float) $parts[1];
            }
        }

        if (isset($breakdown['anon'], $breakdown['file'])) {
            return $breakdown;
        }
    }

    return array();
}

function memoryCreateSection() {
    $currentBytes = readUserMemoryCurrentBytes();
    if ($currentBytes === null) {
        $currentBytes = readSystemdMemoryCurrentBytes();
    }

    $limitBytes = readUserRamLimitBytes();
    $memoryBreakdown = readUserMemoryBreakdownBytes();
    if (!isset($memoryBreakdown['anon'], $memoryBreakdown['file'])) {
        $memoryBreakdown = readSystemdMemoryBreakdownBytes();
    }
    $processBytes = isset($memoryBreakdown['anon']) ? (float) $memoryBreakdown['anon'] : null;
    $cacheBytes = isset($memoryBreakdown['file']) ? (float) $memoryBreakdown['file'] : null;

    if ($currentBytes === null && $limitBytes === null && $processBytes === null && $cacheBytes === null) {
        return '<h6>RAM Info</h6><b>RAM usage data is unavailable right now.</b><hr />';
    }

    $currentText = ($currentBytes === null) ? 'n/a' : pmssFormatBytes($currentBytes, 2, 0, true);
    $processText = ($processBytes === null) ? 'n/a' : pmssFormatBytes($processBytes, 2, 0, true);
    $cacheText = ($cacheBytes === null) ? 'n/a' : pmssFormatBytes($cacheBytes, 2, 0, true);

    if ($limitBytes === null || $limitBytes <= 0) {
        $breakdownText = '';
        if ($processBytes !== null || $cacheBytes !== null) {
            $breakdownText = '<br />Process memory: '.$processText.'<br />Page cache: '.$cacheText;
        }
        return <<<EOF
<h6>RAM Info</h6>
Current RAM usage: {$currentText}{$breakdownText}<br />
RAM limit: n/a
<hr />
EOF;
    }

    $limitText = pmssFormatBytes($limitBytes, 2, 0, true);

    if ($currentBytes === null && $processBytes === null && $cacheBytes === null) {
        return <<<EOF
<h6>RAM Info</h6>
Current RAM usage: n/a<br />
RAM limit: {$limitText}
<hr />
EOF;
    }

    $warningBytes = $processBytes !== null ? $processBytes : $currentBytes;
    $warningPercent = round(($warningBytes / $limitBytes) * 100, 1);
    if (!is_finite($warningPercent)) {
        $warningPercent = 0;
    }

    $pressureStatus = null;
    if (function_exists('pmssWebCgroupMemoryStatusRead')) {
        $readPressureStatus = pmssWebCgroupMemoryStatusRead();
        if (!empty($readPressureStatus['available'])) {
            $pressureStatus = $readPressureStatus;
        }
    }

    $hasOomEvents = is_array($pressureStatus)
        && ((int) ($pressureStatus['max_events'] ?? 0) > 0
            || (int) ($pressureStatus['oom_events'] ?? 0) > 0
            || (int) ($pressureStatus['oom_kill_events'] ?? 0) > 0);
    $isThrottleActive = is_array($pressureStatus)
        && (string) ($pressureStatus['status'] ?? '') === 'THROTTLED'
        && !$hasOomEvents;

    if ($processBytes !== null && $cacheBytes !== null) {
        $usedBytes = max($processBytes + $cacheBytes, $currentBytes !== null ? $currentBytes : 0);
        $usedPercent = round(($usedBytes / $limitBytes) * 100, 1);
        if (!is_finite($usedPercent)) {
            $usedPercent = 0;
        }
        $processPercent = round(($processBytes / $limitBytes) * 100, 1);
        $cachePercent = round(($cacheBytes / $limitBytes) * 100, 1);
        $remainingPercent = max(0, 100 - max(0, min(100, $processPercent)) - max(0, min(100, $cachePercent)));
        if ($remainingPercent < 0) {
            $remainingPercent = 0;
        }
        $titleText = 'Process: '.$processText.' | Cache: '.$cacheText.' | Limit: '.$limitText;
        $gauge = createStackedGauge(
            $titleText,
            $titleText,
            $usedPercent,
            array(
                array('width' => $processPercent, 'color' => '#'.gaugeColor($warningPercent)),
                array('width' => $cachePercent, 'color' => '#b0bec5'),
                array('width' => $remainingPercent, 'color' => 'transparent'),
            )
        );
    } else {
        $percent = round((($currentBytes !== null ? $currentBytes : 0) / $limitBytes) * 100, 1);
        if (!is_finite($percent)) {
            $percent = 0;
        }
        $titleText = "{$currentText} / {$limitText}";
        $gauge = createGauge($titleText, $titleText, $percent);
    }

    if ($isThrottleActive) {
        $warning = '<br /><b style="color: #d2691e;">RAM THROTTLE ACTIVE</b><br />Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed.<br />';
    } elseif ($warningPercent > 100) {
        $warning = '<br /><b style="color: red;">RAM LIMIT EXCEEDED</b><br />Processes may be killed (OOM) until memory usage drops.<br />';
    } elseif ($warningPercent >= 80) {
        $warning = '<br /><b style="color: #d2691e;">RAM WARNING</b><br />You are close to your RAM limit. Consider reducing running services or upgrading your plan.<br />';
    } else {
        $warning = '';
    }

    $pressureIndicator = '';
    if (is_array($pressureStatus)) {
        $pressureParts = array(
            '<br /><b>Memory pressure:</b> <span style="color: '.$pressureStatus['status_color'].';">&#9679; '.htmlspecialchars($pressureStatus['status'], ENT_QUOTES, 'UTF-8').'</span>',
            '<br />Throttle events: '.number_format((int) $pressureStatus['throttle_events']),
        );
        if ($pressureStatus['message'] !== '') {
            $pressureParts[] = '<br /><b style="color: '.$pressureStatus['status_color'].';">'.htmlspecialchars($pressureStatus['message'], ENT_QUOTES, 'UTF-8').'</b>';
        }

        $pressureIndicator = implode('', $pressureParts).'<br />';
    }

    return <<<EOF
<h6>RAM Info</h6>
{$gauge}
{$pressureIndicator}
{$warning}
<hr />
EOF;
}

	function trafficCreateSection($trafficData, $trafficLimit, $trafficIngress = null, $bonusTraffic = 0, $trafficBandwidthState = array(), $billingId = 0) {
	    if (count($trafficData) == 0) return;
	    $bandwidthNote = pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingId);

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
	    <div style="margin-top: 3px; line-height: 1.35;">{$bandwidthNote}</div>
	    This is rolling past 30 days, <a href="https://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/" target="_blank">read more</a>.
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
	    <div style="margin-top: 3px; line-height: 1.35;">{$bandwidthNote}</div>
	    {$inboundLine}{$ratioLine}
	    This is rolling past 30 days, <a href="https://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/" target="_blank">read more</a>.
	    <hr />
EOF;
}

function createStackedGauge($titleText, $footerText, $percent, $segments) {
    if (!is_finite($percent)) $percent = 0;
    $barHtml = '';
    $filledPercent = 0;
    foreach ($segments as $segment) {
        $width = isset($segment['width']) && is_numeric($segment['width']) ? (float) $segment['width'] : 0;
        $width = max(0, min(100 - $filledPercent, $width));
        if ($width <= 0) {
            continue;
        }
        $filledPercent += $width;
        $color = isset($segment['color']) ? (string) $segment['color'] : 'transparent';
        $barHtml .= '<div style="float: left; width: '.$width.'%; background-color: '.$color.'; visibility: visible;">&nbsp;</div>';
    }

    return createGaugeFrameHtml($titleText, $footerText, $percent, $barHtml);
}

function createGauge($titleText, $footerText, $percent, $percentMax = 0) {
    if (!is_finite($percent)) $percent = 0;
    if (!is_finite($percentMax)) $percentMax = 0;
    if ($percentMax == 0) $percentMax = $percent;

    return createGaugeFrameHtml(
        $titleText,
        $footerText,
        $percent,
        '<div id="meter-disk-value" style="float: left; width: '.$percentMax.'%; background-color: #'.gaugeColor($percent).'; visibility: visible;">&nbsp;</div>'
    );
}

function createGaugeFrameHtml($titleText, $footerText, $percent, $barHtml) {
    return <<<EOF
    <table style="margin: 0; padding: 0;">
        <tr>
            <td id="meter-disk-td" title="{$titleText}">
                <div id="meter-disk-holder">
                    <span id="meter-disk-text" style="overflow-x: visible; overflow-y: visible;">{$percent}%</span>
                    {$barHtml}
                </div>
            </td>
        </tr>
    </table>
    <span style="font-size: 1.05em; float: right; text-align: right; line-height: 13px;">{$footerText}</span>
EOF;
}

function gaugeColor($percent) {
    if (!is_finite($percent)) $percent = 0;

    if ($percent > 100) {
        return 'FF4040';
    }

    $startColor = array(0x99, 0xE6, 0x99);
    $endColor = array(0xEE, 0x99, 0x99);
    $channels = array();
    foreach (array(0, 1, 2) as $index) {
        $channels[] = dechex($startColor[$index] - round(($startColor[$index] - $endColor[$index]) * ($percent / 100)));
    }

    return implode('', $channels);
}

function quotaCreateSection($quotaInfo, $bonusQuota = 0) {
    if (count($quotaInfo) == 0) return '';

    $hardLimit  = $quotaInfo['hardLimit'];
    $totalSpace = $quotaInfo['totalSpace'];
    $usedBytes  = $quotaInfo['usedBytes'];

    if ($hardLimit == 0 || $totalSpace == 0) {
        return '<b>Warning:</b> Quota info is missing. If this persists for more than an hour, contact support.';
    }

    $percent = ($totalSpace > 0) ? round(($usedBytes / $totalSpace) * 100, 1) : 0;
    $percentFromBurst = ($hardLimit > 0) ? round(($usedBytes / $hardLimit) * 100) : 0;
    if ($percent < 100 && $totalSpace > 0) {
        $percentFromBurst = round(($usedBytes / $totalSpace) * 100, 1);
    }

    $readableUsed   = pmssFormatBytes($usedBytes, 2, 0, true);
    $readableQuota  = pmssFormatBytes($totalSpace, 2, 0, true);
    $readableBurst  = pmssFormatBytes($hardLimit, 2, 0, true);

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

?>
