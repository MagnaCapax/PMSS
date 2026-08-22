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
require_once __DIR__.'/scriptsInc.php';

if (isset($_GET['backup']) && $_GET['backup'] === 'download') {
    pmssCustomerBackupDownload(dirname(__DIR__));
    exit;
}

// Customer-side helpers MUST live in the customer tree (etc/skel/www/) because
// per-user lighttpd runs as the customer UID and cannot traverse /scripts/
// (intentionally 750 root:root — the operator-only security boundary).
foreach ([
    'welcomeAnnouncements.php',
    'webCgroupMemoryStatus.php',
    'userMediaStackPanel.php',
    'userTrafficLimit.php',
] as $pmssWelcomeHelper) {
    pmssWelcomeRequireLocalHelper($pmssWelcomeHelper);
}
// traffic/storage.php stays out of customer PHP; userTrafficLimit.php owns the state reader (ADR 0016).


$pageState = pmssWelcomePageStateBuild();
$quotaInfo = $pageState['quotaInfo'];
$bonusQuota = $pageState['bonusQuota'];
$bonusDisplayState = $pageState['bonusDisplayState'];
$bonusDisplayNote = function_exists('pmssCustomerBonusDisplayNoteBuild')
    ? pmssCustomerBonusDisplayNoteBuild($bonusDisplayState)
    : '';
$trafficLimitState = $pageState['trafficLimitState'];
$bonusTraffic = $trafficLimitState['bonusGiB'];
$vendor = $pageState['vendor'];
$contextualWelcomeMessage = $pageState['contextualWelcomeMessage'];
$delugePasswordCanRotate = $pageState['delugePasswordCanRotate'];
$delugePasswordNotice = $pageState['delugePasswordNotice'];
$delugePassword = $pageState['delugePassword'];
$mediaStackStatus = $pageState['mediaStackStatus'];
$billingServiceId = $pageState['billingServiceId'];
$trafficBandwidthState = $pageState['trafficBandwidthState'];
$welcomeHeadingHtml = pmssWelcomeHeadingHtmlBuild($contextualWelcomeMessage);
$announcementItemsHtml = pmssWelcomeAnnouncementItemsHtmlBuild();
$homeRaidNoticeHtml = pmssWelcomeHomeRaidNoticeHtmlRead();
$managedApps = pmssCustomerManagedAppDefinitions();
$serviceRestartActions = pmssWelcomeServiceRestartActionsBuild($managedApps, $mediaStackStatus);
$serviceRestartActionsJson = json_encode($serviceRestartActions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($serviceRestartActionsJson)) $serviceRestartActionsJson = '[]';
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
        .pmss-bonus-banner {
            flex: 0 0 100%;
            box-sizing: border-box;
            margin: 0 0 8px;
            padding: 18px 20px;
            border: 1px solid #4fc3f7;
            border-radius: 8px;
            background: linear-gradient(135deg, #12334a 0%, #1d3d55 100%);
            color: #ffffff;
            text-align: center;
        }

        .pmss-bonus-banner strong {
            display: block;
            font-size: 2.4rem;
            letter-spacing: 0.04em;
            line-height: 1.1;
        }

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
        .pmss-traffic-disclosure {
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid #d8a55a;
            background: #fff6e5;
            color: #5f3b00;
            line-height: 1.45;
        }
        .pmss-traffic-disclosure-active {
            border-color: #cc8f8f;
            background: #fff1f1;
            color: #7a1a1a;
        }
    </style>
    <script type="text/javascript">
        var pmssActionNoticeTimer = null;
        var pmssMediaStackPollTimer = null;
        var pmssServiceRestartActions = <?php echo $serviceRestartActionsJson; ?>;

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

        function pmssActionRequest(action, passwordValue) {
            var deferred = $.Deferred();
            var request = {
                url: action.url,
                cache: false,
                data: null,
                type: action.type || 'GET',
                success: function(payload) {
                    deferred.resolve(payload);
                },
                error: function(xhr) {
                    if (xhr.status === 428 && action.passwordField) {
                        var password = window.prompt('Enter your account password to sync qBittorrent WebUI login.');
                        if (password !== null) {
                            pmssActionRequest(action, password).done(function(payload) {
                                deferred.resolve(payload);
                            }).fail(function(retryXhr, cancelled) {
                                deferred.reject(retryXhr, cancelled);
                            });
                        } else {
                            deferred.reject(xhr, true);
                        }
                        return;
                    }
                    deferred.reject(xhr, false);
                }
            };
            if (action.dataType) request.dataType = action.dataType;
            if (action.headers) request.headers = action.headers;
            if (action.passwordField && passwordValue !== undefined) {
                request.type = 'POST';
                request.data = {};
                request.data[action.passwordField] = passwordValue;
            }
            $.ajax(request);
            return deferred.promise();
        }

        function pmssRunAction(button, url, successMessage, shouldReload, pendingMessage, passwordFieldName, passwordValue) {
            pmssSetActionLoading(button, true);
            if (pendingMessage) pmssShowActionNotice(pendingMessage, false);

            var action = {
                url: url,
                passwordField: passwordFieldName || (url.indexOf('qbittorrent.php') === 0 ? 'qbittorrentPassword' : '')
            };
            pmssActionRequest(action, passwordValue).done(function() {
                pmssSetActionLoading(button, false);
                if (successMessage) pmssShowActionNotice(successMessage, false);
                if (shouldReload) window.setTimeout(function() { location.reload(true); }, 900);
            }).fail(function(xhr, cancelled) {
                pmssSetActionLoading(button, false);
                if (!cancelled) pmssShowActionNotice('Action failed. Please try again in a moment.', true);
            });
        }

        function pmssRestartAllServices(button) {
            var actions = pmssServiceRestartActions.slice(0);
            var failures = [];
            var actionIndex = 0;
            pmssSetActionLoading(button, true);

            function runNext() {
                if (actionIndex >= actions.length) {
                    pmssSetActionLoading(button, false);
                    if (failures.length > 0) {
                        pmssShowActionNotice('Restart requests failed or were cancelled for: ' + failures.join(', ') + '.', true);
                    } else {
                        pmssShowActionNotice('Restart requests sent for all available account services.', false);
                    }
                    return;
                }

                var action = actions[actionIndex++];
                pmssShowActionNotice('Restarting ' + action.label + '...', false);
                pmssActionRequest(action).done(runNext).fail(function() {
                    failures.push(action.label);
                    runNext();
                });
            }

            runNext();
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
            var recoveryButton = $('#pmss-media-stack-recovery');

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

            if (recoveryButton.length > 0 && payload) {
                if (payload.canRestart) {
                    recoveryButton.removeAttr('disabled').show();
                } else {
                    recoveryButton.attr('disabled', 'disabled').hide();
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

        function pmssMediaStackAction(button, action, pendingMessage, failureMessage) {
            pmssSetActionLoading(button, true);
            pmssShowActionNotice(pendingMessage, false);

            $.ajax({
                url: 'mediaStack.php?action=' + action,
                type: 'POST',
                dataType: 'json',
                cache: false,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
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
                    pmssShowActionNotice(failureMessage, true);
                }
            });
        }

        function pmssMediaStackStart(button) {
            pmssMediaStackAction(button, 'start', 'Starting media stack install...', 'Media stack install could not be started from the panel.');
        }

        function pmssMediaStackStartStopped(button) {
            pmssMediaStackAction(button, 'start-stopped', 'Starting stopped media-stack apps...', 'Stopped media-stack apps could not be started from the panel.');
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
                    <div class="pmss-bonus-banner" role="status">
                        <strong><?php echo pmssCustomerHtmlAttr(pmssCustomerBonusDisplayTextBuild($bonusDisplayState)); ?></strong>
                        <?php if ($bonusDisplayNote !== ''): ?><small class="pmss-bonus-note"><?php echo pmssCustomerHtmlAttr($bonusDisplayNote); ?></small><?php endif; ?>
                    </div>
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

                        <h6>All account services</h6>
                        <p>Restart every available service for this account. Installed media-stack apps that are stopped are started without disturbing live tmux sessions.</p>
                        <input type="button" name="servicesRestart" value="Restart all my services" onClick="pmssRestartAllServices(this);" />

<?php echo pmssWelcomeManagedAppsHtmlBuild($managedApps, $delugePasswordCanRotate, $delugePasswordNotice, $delugePassword); ?>

<?php
if (file_exists('mediaStack.php') && function_exists('pmssMediaStackPanelHtmlBuild')) {
?>
                        <h6>Media Stack</h6>
                        <div id="pmss-media-stack-status"><?php echo pmssMediaStackPanelHtmlBuild($mediaStackStatus); ?></div>
                        <input type="button" id="pmss-media-stack-start" name="mediaStackStart" value="Install Media Stack" onClick="pmssMediaStackStart(this);"<?php if (empty($mediaStackStatus['canStart'])) echo ' disabled="disabled"'; ?> />
                        <input type="button" id="pmss-media-stack-recovery" name="mediaStackRecovery" value="Start stopped apps" onClick="pmssMediaStackStartStopped(this);"<?php if (empty($mediaStackStatus['canRestart'])) echo ' disabled="disabled" style="display:none"'; ?> />
<?php
}
?>

                        <h6>rTorrent</h6>
                        <input type="button" name="rtorrentRestart" value="Restart rTorrent" onClick="pmssRunAction(this, 'rtorrentRestart.php', 'rTorrent restart request sent, please allow up to 2 minutes for restart to happen.', false, 'Sending rTorrent restart request...');" />
                        <h6>Torrent configuration backup</h6>
                        <p>Download rTorrent, ruTorrent, Deluge, and qBittorrent configuration plus torrent session/resume state. Media files are not included.</p>
                        <p><b>Keep the archive private:</b> it can contain private tracker URLs and separate application credentials.</p>
                        <p><a href="welcome.php?backup=download" rel="nofollow">Download torrent configuration backup</a></p>
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
                        echo quotaCreateSection($quotaInfo, $bonusQuota, $bonusDisplayState);

                        $trafficLimit = (int) $trafficLimitState['limitGiB'];
                        $trafficData = pmssWelcomeSerializedArrayRead('../.trafficData');
                        if ($trafficData !== null) {
                            trafficCreateSection($trafficData, $trafficLimit, pmssWelcomeSerializedArrayRead('../.trafficDataIngress'), $bonusTraffic, $trafficBandwidthState, $billingServiceId);
                        } elseif (@file_exists('../.trafficLimit')) {
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
                            echo pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId).'<br />';
                        }

                        echo pmssWelcomeMemorySectionHtmlBuild();

                        if ($billingServiceId > 0) {
                            echo <<<EOF
                            <h6>Need more resources?</h6>
                    <p>Need more disk space, traffic, or RAM?</p>
                            <p>You can upgrade fast and easy — activation usually within few minutes! Just check out your <a href="https://pulsedmedia.com/clients/upgrade.php?type=configoptions&id={$billingServiceId}" target="_blank">Upgrade Options!</a></p>
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

    if (function_exists('pmssMediaStackPanelStatusRead')) {
        $hostname = function_exists('gethostname') ? (string) gethostname() : '';
        $mediaStackStatus = pmssMediaStackPanelStatusRead(
            $home,
            basename(rtrim($home, '/')),
            $hostname !== '' ? $hostname : (string) php_uname('n')
        );
    }

    $trafficLimitState = function_exists('pmssTrafficLimitStateRead')
        ? pmssTrafficLimitStateRead('../.trafficLimit', '../.bonusTraffic')
        : array('limitGiB' => 0, 'bonusGiB' => 0, 'effectiveLimitGiB' => 0);
    $billingServiceId = pmssWelcomeBillingServiceIdRead('../.billingServiceId', '../.billingId');
    $trafficBandwidthState = pmssWelcomeTrafficBandwidthStateBuild('../.throttle');

    return array(
        'quotaInfo' => $quotaInfo,
        'bonusQuota' => (int) (pmssCustomerPositiveIntegerFileRead('../.bonusQuota') ?? 0),
        'bonusDisplayState' => pmssCustomerBonusDisplayStateRead(),
        'trafficLimitState' => $trafficLimitState,
        'vendor' => pmssWelcomeVendorRead(),
        'contextualWelcomeMessage' => pmssWelcomeContextualMessageBuild($quotaInfo),
        'delugePasswordCanRotate' => $delugeState['canRotate'],
        'delugePasswordNotice' => $delugeState['passwordNotice'],
        'delugePassword' => $delugeState['password'],
        'mediaStackStatus' => $mediaStackStatus,
        'billingServiceId' => $billingServiceId,
        'trafficBandwidthState' => $trafficBandwidthState,
    );
}

/**
 * Read billing service ID for upgrade links, with legacy file fallback.
 */
function pmssWelcomeBillingServiceIdRead($primaryPath, $legacyPath) {
    foreach (array($primaryPath, $legacyPath) as $path) {
        $billingServiceId = pmssCustomerPositiveIntegerFileRead($path);
        if ($billingServiceId !== null) {
            return $billingServiceId;
        }
    }

    return 0;
}

/**
 * Resolve baseline and currently effective per-account bandwidth caps.
 *
 * @return array{defaultCapMbit:int,effectiveCapMbit:int,isReduced:bool,throttleFileExists:bool,throttleEnforced:bool,throttleFileMtime:?int}
 */
function pmssWelcomeTrafficBandwidthStateBuild($throttlePath, $enabledMarkerPath = null) {
    $defaultCapMbit = pmssWelcomeTrafficDefaultCapMbitRead();
    $effectiveCapMbit = $defaultCapMbit;

    $parsedCap = pmssCustomerPositiveIntegerFileRead($throttlePath);
    $throttleEnforced = $parsedCap !== null && pmssWelcomeTrafficThrottleEnforced($throttlePath, $enabledMarkerPath);
    if ($throttleEnforced) {
        $effectiveCapMbit = $parsedCap;
    }

    $throttleFileMtime = $throttleEnforced ? pmssWelcomeRegularFileMtimeRead($throttlePath) : null;
    return array(
        'defaultCapMbit' => $defaultCapMbit,
        'effectiveCapMbit' => $effectiveCapMbit,
        'isReduced' => $effectiveCapMbit < $defaultCapMbit,
        'throttleFileExists' => $parsedCap !== null,
        'throttleEnforced' => $throttleEnforced,
        'throttleFileMtime' => $throttleFileMtime,
    );
}

/**
 * Check the root-side runtime marker before trusting a persistent throttle cap.
 */
function pmssWelcomeTrafficThrottleEnforced($throttlePath, $enabledMarkerPath = null) {
    if (!is_string($enabledMarkerPath) || $enabledMarkerPath === '') {
        $enabledMarkerPath = pmssWelcomeTrafficThrottleMarkerPath($throttlePath);
    }
    if (!is_string($enabledMarkerPath) || $enabledMarkerPath === '') {
        return false;
    }

    return is_file($enabledMarkerPath) && !is_link($enabledMarkerPath);
}

/**
 * Resolve the traffic-limit runtime marker that FireQOS also treats as active.
 */
function pmssWelcomeTrafficThrottleMarkerPath($throttlePath) {
    $homePath = is_string($throttlePath) && $throttlePath !== '' ? @realpath(dirname($throttlePath)) : false;
    if (!is_string($homePath) || $homePath === '') {
        return null;
    }

    $userName = basename($homePath);
    if (!is_string($userName) || $userName === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $userName)) {
        return null;
    }

    $stateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
    $stateDir = is_string($stateDir) && $stateDir !== '' ? rtrim($stateDir, '/') : '/var/run/pmss/trafficLimits';
    if ($stateDir === '' || $stateDir[0] !== '/' || strpos($stateDir, "\0") !== false) {
        $stateDir = '/var/run/pmss/trafficLimits';
    }

    return $stateDir.'/'.$userName.'.enabled';
}

/**
 * Read a regular file mtime without following symlinks.
 */
function pmssWelcomeRegularFileMtimeRead($path) {
    if (!is_string($path) || $path === '' || !is_file($path) || is_link($path)) {
        return null;
    }

    $mtime = @filemtime($path);
    return is_int($mtime) && $mtime > 0 ? $mtime : null;
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

    $userCapMbit = pmssWelcomeUserConfigNumber('trafficCapMbit', true);
    if ($userCapMbit !== null) {
        $userCapMbit = (int) $userCapMbit;
        if ($userCapMbit > 0) {
            $defaultCapMbit = $userCapMbit;
        }
    }

    return $defaultCapMbit;
}

/**
 * Build tiny traffic-cap disclosure text shown near usage gauge.
 */
function pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId) {
    $effectiveCapMbit = isset($trafficBandwidthState['effectiveCapMbit']) && is_numeric($trafficBandwidthState['effectiveCapMbit'])
        ? (int) $trafficBandwidthState['effectiveCapMbit']
        : 0;
    $isReduced = isset($trafficBandwidthState['isReduced']) && $trafficBandwidthState['isReduced'] === true;
    if (!$isReduced) {
        return '<span style="font-size: 0.82em; color: #666;">Current effective: full plan port speed</span>';
    }

    $upgradeUrl = 'https://pulsedmedia.com/clients/upgrade.php?type=configoptions';
    if ((int) $billingServiceId > 0) {
        $upgradeUrl .= '&id='.(int) $billingServiceId;
    }

    $effectiveText = number_format(max(0, $effectiveCapMbit));

    return '<span style="font-size: 0.82em; color: #7a1a1a;">Current effective: '.$effectiveText.' Mbps (reduced)</span>'
        . '<br /><span style="font-size: 0.82em;"><a href="'.pmssCustomerHtmlAttr($upgradeUrl).'" target="_blank">Need more bandwidth? Upgrade your plan.</a></span>';
}

/**
 * Build customer-visible traffic throttle status for capped accounts.
 */
function pmssWelcomeTrafficDisclosureHtmlBuild($trafficPercent, $trafficBandwidthState, $billingServiceId) {
    $usageText = number_format((float) $trafficPercent, 1);
    $lines = array(
        pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId),
        'Usage: <b>'.$usageText.'%</b> of monthly traffic cap.',
    );

    $isReduced = !empty($trafficBandwidthState['isReduced']);
    $effectiveCapMbit = isset($trafficBandwidthState['effectiveCapMbit']) && is_numeric($trafficBandwidthState['effectiveCapMbit'])
        ? max(0, (int) $trafficBandwidthState['effectiveCapMbit'])
        : 0;
    $throttleFileMtime = isset($trafficBandwidthState['throttleFileMtime']) && is_numeric($trafficBandwidthState['throttleFileMtime'])
        ? (int) $trafficBandwidthState['throttleFileMtime']
        : null;
    $capText = number_format($effectiveCapMbit).' Mbps';

    if ($isReduced && (float) $trafficPercent > 100.0) {
        $overagePercent = max(0.0, (float) $trafficPercent - 100.0);
        $lines[] = '<b>Throttled to '.$capText.'</b> - usage is '.number_format($overagePercent, 1).'% over cap. Throttle lift requires 3 consecutive days under cap.';
    } elseif ($isReduced) {
        $cooldown = pmssWelcomeTrafficCooldownTextBuild($throttleFileMtime);
        $lines[] = '<b>Throttle cooldown active</b> - current ceiling is '.$capText.'; '.$cooldown;
    } elseif ((float) $trafficPercent >= 70.0) {
        $lines[] = '<b>Approaching monthly traffic cap</b> - review the graduated throttling policy before crossing the cap.';
    }

    $lines[] = 'Policy: <a href="https://wiki.pulsedmedia.com/index.php/Bandwidth#Graduated_throttling" target="_blank">Graduated throttling</a>.';
    $class = $isReduced ? 'pmss-traffic-disclosure pmss-traffic-disclosure-active' : 'pmss-traffic-disclosure';

    return '<div class="'.$class.'">'.implode('<br />', $lines).'</div>';
}

/**
 * Explain the under-cap cooldown without depending on customer-readable timers.
 */
function pmssWelcomeTrafficCooldownTextBuild($throttleFileMtime) {
    $period = 3 * 24 * 60 * 60;
    if (!is_int($throttleFileMtime) || $throttleFileMtime <= 0) {
        return 'it lifts after 3 consecutive days under cap.';
    }

    $remaining = $period - max(0, time() - $throttleFileMtime);
    if ($remaining <= 0) {
        return 'cooldown is ready to lift on the next traffic-limit cron pass.';
    }

    return 'remaining under-cap cooldown: about '.pmssWelcomeDurationCompact($remaining).'.';
}

/**
 * Format a short customer-facing duration for throttle cooldowns.
 */
function pmssWelcomeDurationCompact($seconds) {
    $seconds = max(0, (int) $seconds);
    if ($seconds >= 86400) {
        $days = (int) ceil($seconds / 86400);
        return $days.' day'.($days === 1 ? '' : 's');
    }
    if ($seconds >= 3600) {
        $hours = (int) ceil($seconds / 3600);
        return $hours.' hour'.($hours === 1 ? '' : 's');
    }
    $minutes = (int) max(1, ceil($seconds / 60));
    return $minutes.' minute'.($minutes === 1 ? '' : 's');
}

function pmssWelcomeMetricSectionHtmlBuild($title, $bodyHtml) {
    return '<h6>'.pmssWelcomeHtmlAttr($title).'</h6>'.(string) $bodyHtml.'<hr />';
}

/**
 * Calculate a finite percentage for quota, traffic, and RAM gauges.
 */
function pmssWelcomePercent($used, $limit, $precision = 1) {
    if (!is_numeric($used) || !is_numeric($limit) || (float) $limit <= 0) {
        return 0;
    }

    $percent = round(((float) $used / (float) $limit) * 100, (int) $precision);
    return is_finite($percent) ? $percent : 0;
}

function pmssWelcomeQuotaInfoRead() {
    if (!isset($_GET['quota']) || !is_string($_GET['quota'])) {
        return array();
    }

    $quotaInfo = urldecode($_GET['quota']);
    $quotaInfo = str_replace('\\', '', $quotaInfo); // Serialized data might be malformed with \ chars!
    $quotaInfo = pmssWelcomeSerializedArrayDecode($quotaInfo);

    return is_array($quotaInfo) ? $quotaInfo : array();
}

function pmssWelcomeVendorRead() {
    $vendorDefault = array(
        'name' => 'Pulsed Media'
    );

    $vendor = pmssCustomerSerializedArrayFileRead('/etc/seedbox/config/vendor', 4096);
    if (!is_array($vendor) || count($vendor) == 0 || !isset($vendor['name']) || empty($vendor['name'])) {
        return $vendorDefault;
    }

    return $vendor;
}

function pmssWelcomeContextualMessageBuild($quotaInfo) {
    if (!pmssWelcomeRequireLocalHelper('welcomeMessage.php')) {
        return '';
    }

    if (!function_exists('pmssWelcomeMessageForUser')) {
        return '';
    }

    $userHome = @realpath(dirname(__DIR__));
    if (!is_string($userHome) || $userHome === '') {
        $userHome = dirname(__DIR__);
    }

    $username = basename($userHome);
    if ($username === '' || $username === '.' || $username === '..') {
        $username = (string) @get_current_user();
    }

    return pmssWelcomeMessageForUser($quotaInfo, $userHome, $username);
}

function pmssWelcomeHomeRaidNoticeHtmlRead() {
    // Customer-side storage-health notice (see storageHealthNotice.php).
    if (!pmssWelcomeRequireLocalHelper('storageHealthNotice.php')) {
        return '';
    }

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
    pmssWelcomeRequireLocalHelper('userPasswords.php');

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

function pmssWelcomeHtmlAttr($value) { return pmssCustomerHtmlAttr($value); }

function pmssWelcomeJsSingleQuoted($value) { return str_replace(array('\\', "'", "\r", "\n"), array('\\\\', "\\'", '\\r', '\\n'), (string) $value); }

function pmssWelcomeServiceAvailable($scriptPath, array $binaryPaths) { if (!file_exists($scriptPath)) return false; foreach ($binaryPaths as $binaryPath) if (file_exists((string) $binaryPath)) return true; return false; }

/** Build the ordered queue for the whole-account restart control. */
function pmssWelcomeServiceRestartActionsBuild(array $managedApps, array $mediaStackStatus) {
    $actions = array();
    if (file_exists('rtorrentRestart.php')) $actions[] = array('label' => 'rTorrent', 'url' => 'rtorrentRestart.php');

    foreach ($managedApps as $appName => $definition) {
        if (!is_array($definition)
            || !isset($definition['endpoint'], $definition['binaries'], $definition['enable'])
            || !is_array($definition['binaries'])
            || !file_exists((string) $definition['enable'])
            || !pmssWelcomeServiceAvailable((string) $definition['endpoint'], $definition['binaries'])) continue;

        $action = array('label' => (string) $appName, 'url' => (string) $definition['endpoint'].'?action=restart');
        if ($appName === 'qBittorrent') $action['passwordField'] = 'qbittorrentPassword';
        $actions[] = $action;
    }

    if (!empty($mediaStackStatus['canRestart']) && file_exists('mediaStack.php')) {
        $actions[] = array(
            'label' => 'stopped media-stack apps',
            'url' => 'mediaStack.php?action=start-stopped',
            'type' => 'POST',
            'dataType' => 'json',
            'headers' => array('X-Requested-With' => 'XMLHttpRequest'),
        );
    }

    // A graceful lighttpd restart can interrupt this page, so send it last.
    if (file_exists('lighttpdRestart.php')) $actions[] = array('label' => 'Lighttpd', 'url' => 'lighttpdRestart.php?action=confirm-restart');
    return $actions;
}

function pmssWelcomeActionButtonHtmlBuild($name, $value, $url, $successMessage, $shouldReload, $pendingMessage) {
    return '<input type="button" name="'.pmssWelcomeHtmlAttr($name).'" value="'.pmssWelcomeHtmlAttr($value).'" onClick="pmssRunAction(this, \''
        .pmssWelcomeJsSingleQuoted($url).'\', \''
        .pmssWelcomeJsSingleQuoted($successMessage).'\', '
        .($shouldReload ? 'true' : 'false').', \''
        .pmssWelcomeJsSingleQuoted($pendingMessage).'\');" />';
}

function pmssWelcomeManagedAppsHtmlBuild(array $managedApps, $delugePasswordCanRotate, $delugePasswordNotice, $delugePassword) {
    $delugeHtml = '<p>Deluge Web UI password: <b>'.pmssWelcomeHtmlAttr($delugePassword === '' ? 'Unavailable' : $delugePassword).'</b> (also used for the daemon connection; separate from your account password)</p>';
    if ($delugePasswordCanRotate) $delugeHtml .= '<form method="post" action=""><input type="hidden" name="delugePasswordRotate" value="1" /><input type="submit" value="Rotate Deluge Password" /></form>';
    else $delugeHtml .= '<p><b>Deluge password rotation is unavailable on this host.</b></p>';
    $delugeHtml .= $delugePasswordNotice !== '' ? '<p><b>'.pmssWelcomeHtmlAttr($delugePasswordNotice).'</b></p>' : '';

    $specs = array(
        array('Deluge', 'deluge', 'Deluge', $delugeHtml, 'Start Deluge', 'Deluge starting. Accessible at /deluge-USERNAME/. Refresh GUI to see tab.', 'Deluge start request sent...', 'Disable Deluge', 'Deluge disabled.', 'Disabling Deluge...', 'Restart Deluge', 'Deluge restart requested.', 'Restarting Deluge...'),
        array('rclone', 'rclone', 'Rclone Web UI', '<p>Rclone password is the same as your web access password</p>', 'Start Rclone', 'Rclone starting, access at /user-USERNAME/rclone. Refresh GUI to see tab.', 'Starting Rclone...', 'Disable Rclone', 'Rclone disabled.', 'Disabling Rclone...', 'Restart Rclone', 'Rclone restart requested.', 'Restarting Rclone...'),
        array('qBittorrent', 'qbittorrent', 'qBittorrent', '<p>qBittorrent username is your own username and password matches your account password. Change it once logged in if you want a separate WebUI password. If you get 503, try restarting Lighttpd — port may have changed.</p>', 'Start qBittorrent', 'qBittorrent starting, access at /user-USERNAME/qbittorrent/ — Refresh GUI to see tab.', 'Starting qBittorrent...', 'Disable qBittorrent', 'qBittorrent disabled.', 'Disabling qBittorrent...', 'Restart qBittorrent', 'qBittorrent restart requested.', 'Restarting qBittorrent...'),
    );

    $html = '';
    foreach ($specs as list($appName, $prefix, $heading, $body, $startLabel, $startSuccess, $startPending, $disableLabel, $disableSuccess, $disablePending, $restartLabel, $restartSuccess, $restartPending)) {
        $definition = isset($managedApps[$appName]) && is_array($managedApps[$appName]) ? $managedApps[$appName] : array();
        if (!isset($definition['endpoint'], $definition['binaries'], $definition['enable']) || !is_array($definition['binaries']) || !pmssWelcomeServiceAvailable($definition['endpoint'], $definition['binaries'])) continue;
        $endpoint = (string) $definition['endpoint'];
        $html .= '<h6>'.pmssWelcomeHtmlAttr($heading).'</h6>'.$body;
        $html .= !file_exists((string) $definition['enable']) ? pmssWelcomeActionButtonHtmlBuild($prefix.'Start', $startLabel, $endpoint.'?action=start', $startSuccess, true, $startPending) : pmssWelcomeActionButtonHtmlBuild($prefix.'Disable', $disableLabel, $endpoint.'?action=disable', $disableSuccess, true, $disablePending).pmssWelcomeActionButtonHtmlBuild($prefix.'Restart', $restartLabel, $endpoint.'?action=restart', $restartSuccess, false, $restartPending);
    }

    return $html;
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
    if (!is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    $allowedPaths = array(
        '/clients/announcementsrss.php',
        '/remote/welcomeHeadingText.php',
    );

    // Customer-panel remote fetches are pinned to expected PMSS HTTPS endpoints.
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'pulsedmedia.com'
        || !in_array((string) ($parts['path'] ?? ''), $allowedPaths, true)
        || !function_exists('pmssWelcomeHttpContextCreate')) {
        return false;
    }

    $body = @file_get_contents($url, false, pmssWelcomeHttpContextCreate(), 0, 1048576);
    return is_string($body) ? $body : false;
}

function pmssWelcomeUserConfigNumber($key, $allowSymlink = false) {
    $configPath = '../.config/pmss-user.json';
    $raw = pmssCustomerFileRead($configPath, $allowSymlink);
    $userConfig = is_string($raw) ? pmssJsonDecodeAssoc($raw) : null;
    return is_array($userConfig) && isset($userConfig[$key]) && is_numeric($userConfig[$key])
        ? (float) $userConfig[$key]
        : null;
}

function pmssWelcomeSerializedArrayRead($path) {
    return pmssCustomerSerializedArrayFileRead($path, 1048576);
}

function pmssWelcomeTrafficMonthValueRead($trafficState) {
    if (!is_array($trafficState)
        || !isset($trafficState['raw'])
        || !is_array($trafficState['raw'])
        || !isset($trafficState['raw']['month'])
        || !is_numeric($trafficState['raw']['month'])) {
        return null;
    }

    $month = (float) $trafficState['raw']['month'];
    return is_finite($month) && $month >= 0 ? $month : null;
}

function trafficCreateSection($trafficData, $trafficLimit, $trafficIngress = null, $bonusTraffic = 0, $trafficBandwidthState = array(), $billingServiceId = 0) {
    if (!is_array($trafficData) || count($trafficData) == 0) {
        return;
    }

    $trafficUsedRaw = pmssWelcomeTrafficMonthValueRead($trafficData);
    if ($trafficUsedRaw === null) {
        $bandwidthNote = pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId);
        echo pmssWelcomeMetricSectionHtmlBuild('Traffic Info', "\nTraffic usage data is unavailable right now.<br />\n<div style=\"margin-top: 3px; line-height: 1.35;\">{$bandwidthNote}</div>\n");
        return;
    }

    $outboundMonth = $trafficUsedRaw;
    $trafficUsedRaw = round($trafficUsedRaw);
    $trafficUsed = round($trafficUsedRaw / 1024) . ' GiB';
    $inboundMonth = pmssWelcomeTrafficMonthValueRead($trafficIngress);
    $inboundLine = $inboundMonth !== null ? '<br />Inbound (30 days): '.round($inboundMonth / 1024).' GiB' : '';
    $ratioState = function_exists('pmssTrafficRatioStateBuild') ? pmssTrafficRatioStateBuild($outboundMonth, $inboundMonth) : array('available' => false);
    $ratioLine = !empty($ratioState['available'])
        ? '<br />Inbound:Outbound ratio (30 days): <span style="color: '.$ratioState['color'].'">'.$ratioState['display'].'</span>'
        : '';

    if ($trafficLimit <= 0) {
        $bandwidthNote = pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId);
        echo pmssWelcomeMetricSectionHtmlBuild('Traffic Info', "\nTraffic used (30 days): {$trafficUsed}<br />\nTraffic limit: Unlimited{$inboundLine}{$ratioLine}<br />\n<div style=\"margin-top: 3px; line-height: 1.35;\">{$bandwidthNote}</div>\nThis is rolling past 30 days, <a href=\"https://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/\" target=\"_blank\">read more</a>.\n");
        return;
    }

    $bonusTraffic = max(0, (int) $bonusTraffic);
    $limitTotal = $trafficLimit + $bonusTraffic;
    $trafficUsedGiB = $trafficUsedRaw / 1024;
    $percent = pmssWelcomePercent($trafficUsedGiB, $limitTotal, 0);
    $trafficDisclosure = pmssWelcomeTrafficDisclosureHtmlBuild(pmssWelcomePercent($trafficUsedGiB, $limitTotal, 1), $trafficBandwidthState, $billingServiceId);

    $warning = $percent > 100
        ? '<br /><b style="color: red;">OVER TRAFFIC LIMIT WARNING - REDUCED BANDWIDTH</b><br />You are beyond your traffic limit. Consider upgrading your plan or adding extra traffic.<br />Datacenter external outbound (TO internet) bandwidth limited to 100 Mbps. Datacenter internal and inbound bandwidth is unrestricted.'
        : '';
    $titleText = "{$trafficUsed} / {$limitTotal} GiB";
    $bonusLine = ($bonusTraffic > 0) ? 'Bonus traffic: ' . number_format($bonusTraffic) . ' GiB' : '';
    $usageLine = 'Used: '.$trafficUsed.' / Limit: '.number_format($limitTotal).' GiB (30-day window)';
    $gauge = createGauge($titleText, $bonusLine, $percent);

    echo pmssWelcomeMetricSectionHtmlBuild('Traffic Info', "\n{$usageLine}<br />\n{$gauge}\n{$warning}\n{$trafficDisclosure}\n{$inboundLine}{$ratioLine}\nThis is rolling past 30 days, <a href=\"https://blog.pulsedmedia.com/2016/06/traffic-limits-why-and-what-is-rolling-30-days-limit/\" target=\"_blank\">read more</a>.\n");
}

function createStackedGauge($titleText, $footerText, $percent, $segments) {
    if (!is_finite($percent)) $percent = 0;
    $barHtml = '';
    $filledPercent = 0;
    foreach ($segments as $segment) {
        $width = isset($segment['width']) && is_numeric($segment['width']) ? (float) $segment['width'] : 0;
        if (empty($segment['raw'])) {
            $width = max(0, min(100 - $filledPercent, $width));
            if ($width <= 0) {
                continue;
            }
            $filledPercent += $width;
        }
        $color = isset($segment['color']) ? (string) $segment['color'] : 'transparent';
        $id = isset($segment['id']) ? ' id="'.(string) $segment['id'].'"' : '';
        $barHtml .= '<div'.$id.' style="float: left; width: '.$width.'%; background-color: '.$color.'; visibility: visible;">&nbsp;</div>';
    }

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

function createGauge($titleText, $footerText, $percent, $percentMax = 0) {
    if (!is_finite($percent)) $percent = 0;
    if (!is_finite($percentMax)) $percentMax = 0;
    if ($percentMax == 0) $percentMax = $percent;

    return createStackedGauge(
        $titleText,
        $footerText,
        $percent,
        array(array('id' => 'meter-disk-value', 'width' => $percentMax, 'color' => '#'.gaugeColor($percent), 'raw' => true))
    );
}

function gaugeColor($percent) {
    if (!is_finite($percent)) $percent = 0;

    if ($percent > 100) {
        return 'FF4040';
    }

    $startColor = array(0x99, 0xE6, 0x99);
    $endColor = array(0xEE, 0x99, 0x99);
    return dechex($startColor[0] - round(($startColor[0] - $endColor[0]) * ($percent / 100))).dechex($startColor[1] - round(($startColor[1] - $endColor[1]) * ($percent / 100))).dechex($startColor[2] - round(($startColor[2] - $endColor[2]) * ($percent / 100)));
}

function quotaCreateSection($quotaInfo, $bonusQuota = 0, $bonusDisplayState = array()) {
    if (!is_array($quotaInfo) || count($quotaInfo) == 0) return '';

    $quotaMissingWarning = '<b>Warning:</b> Quota info is missing. If this persists for more than an hour, contact support.';
    $quotaFields = array();
    foreach (array('hardLimit', 'totalSpace', 'usedBytes') as $field) {
        if (!isset($quotaInfo[$field]) || !is_numeric($quotaInfo[$field])) {
            return $quotaMissingWarning;
        }

        $value = (float) $quotaInfo[$field];
        if (!is_finite($value) || $value < 0) {
            return $quotaMissingWarning;
        }
        $quotaFields[$field] = $value;
    }

    $hardLimit  = $quotaFields['hardLimit'];
    $totalSpace = $quotaFields['totalSpace'];
    $usedBytes  = $quotaFields['usedBytes'];

    if ($hardLimit == 0 || $totalSpace == 0) {
        return $quotaMissingWarning;
    }

    $percent = pmssWelcomePercent($usedBytes, $totalSpace, 1);
    $percentFromBurst = pmssWelcomePercent($usedBytes, $hardLimit, 0);
    if ($percent < 100 && $totalSpace > 0) {
        $percentFromBurst = pmssWelcomePercent($usedBytes, $totalSpace, 1);
    }

    $readableUsed   = pmssFormatBytes($usedBytes, 2, 0, true);
    $readableQuota  = pmssFormatBytes($totalSpace, 2, 0, true);
    $readableBurst  = pmssFormatBytes($hardLimit, 2, 0, true);

    $bonusQuotaLine = ($bonusQuota != 0) ? '<br />Bonus disk space: ' . number_format($bonusQuota) . ' GiB' : '';

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

    $gauge = createGauge($titleText, $titleText . $bonusQuotaLine, $percent, $percentFromBurst);

    return <<<EOF
<h6>Quota Info</h6>
{$gauge}
{$warning}
<hr />
EOF;
}

?>
