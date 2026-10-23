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
// #TODO(2026-11-01): Remove the pre-Configure fallback (ADR 0103 removal plan).
$pmssConfigureAvailable = is_file(__DIR__.'/configure.php') && is_file(__DIR__.'/configureSettings.php');
if (isset($_GET['status'])) { pmssCustomerAppsStatusJsonEmit(); exit; }
if (isset($_GET['log'])) { pmssCustomerAppsLogJsonEmit(); exit; }

if (isset($_GET['backup']) && $_GET['backup'] === 'download') {
    pmssCustomerBackupDownload(dirname(__DIR__));
    exit;
}

$configRestoreResult = null;
if (isset($_GET['restore']) && $_GET['restore'] === 'config') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        pmssFrontendPostActionRequired();
    }
    $configRestoreResult = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? pmssCustomerBackupRestore(dirname(__DIR__), (string) ($_POST['archivePath'] ?? ''))
        : pmssCustomerBackupRestoreResult(false, 'Use the restore form to choose an uploaded backup archive.');
}

// Customer-side helpers MUST live in the customer tree (etc/skel/www/) because
// per-user lighttpd runs as the customer UID and cannot traverse /scripts/
// (intentionally 750 root:root — the operator-only security boundary).
foreach ([
    'welcomeAnnouncements.php',
    'webCgroupMemoryStatus.php',
    'userMediaStackPanel.php',
    'userTrafficLimit.php',
    'pikkumiesPanel.php',
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
list($home, $username, $hostname) = pmssCustomerAccountContext();
$pmssPikkumiesState = pmssWelcomePikkumiesStateRead($home, $username);
$appsStatus = pmssCustomerAppsStatusRead($home, $username, $hostname);
$billingServiceId = $pageState['billingServiceId'];
$trafficBandwidthState = $pageState['trafficBandwidthState'];
$welcomeHeadingHtml = pmssWelcomeHeadingHtmlBuild($contextualWelcomeMessage);
$storageHealthNoticeHtml = pmssWelcomeStorageHealthNoticeHtmlRead();

$guiFramesLocalOnly = is_file('../.guiFramesLocalOnly') && !is_link('../.guiFramesLocalOnly');
$usageAlertsPermissions = @fileperms('../.usageAlertsEnabled');
$usageAlertsEnabled = is_file('../.usageAlertsEnabled') && !is_link('../.usageAlertsEnabled')
    && is_int($usageAlertsPermissions) && ($usageAlertsPermissions & 0777) === 0600;


// Welcome home view model (ADR 0147). Every number comes from an existing reader; nothing is recomputed here.
if (isset($_GET['credits'])) { pmssWelcomeHomeCreditsEmit(dirname(__DIR__)); exit; }
if (isset($_GET['support'])) { pmssWelcomeHomeSupportEmit(dirname(__DIR__)); exit; }
if (isset($_GET['news'])) { pmssWelcomeHomeNewsEmit(dirname(__DIR__)); exit; }
$homeTrafficState = pmssWelcomeHomeTrafficStateBuild(
    pmssWelcomeSerializedArrayRead('../.trafficData'),
    pmssWelcomeSerializedArrayRead('../.trafficDataIngress'),
    $trafficLimitState,
    $trafficBandwidthState
);
$homeQuotaState = pmssWelcomeHomeQuotaStateBuild($quotaInfo);
$homeMemoryState = function_exists('pmssWelcomeMemoryStateBuild') ? pmssWelcomeMemoryStateBuild() : array();
$homeAgentFirst = pmssWelcomeHomeAgentFirst($appsStatus);
$homeAlertsHtml = $storageHealthNoticeHtml
    .pmssWelcomeHomeAlertsHtmlBuild($homeQuotaState, $homeTrafficState, $homeMemoryState, $trafficBandwidthState, $billingServiceId);
$homeStatusChipsHtml = pmssWelcomeHomeStatusChipsHtmlBuild($bonusDisplayState, $billingServiceId);
$homeMetricsHtml = pmssWelcomeHomeQuotaMetricHtmlBuild($homeQuotaState, $billingServiceId)
    .pmssWelcomeHomeTrafficMetricHtmlBuild($homeTrafficState, $billingServiceId)
    .pmssWelcomeHomeMemoryMetricHtmlBuild($homeMemoryState)
    .pmssWelcomeHomeServerMetricHtmlBuild();
$homeCreditsKey = pmssWelcomeHomeCreditsKeyRead(dirname(__DIR__)) !== null;
if ($homeCreditsKey) $homeMetricsHtml .= pmssWelcomeHomeCreditsMetricHtmlBuild();
$homeApps = pmssWelcomeHomeAppsBuild($appsStatus, $homeAgentFirst);
$homeAppsHtml = pmssWelcomeHomeAppsHtmlBuild($homeApps);

$homeConnectHtml = pmssWelcomeHomeConnectHtmlBuild($username, $appsStatus, $homeAgentFirst);
$homeNewsHtml = pmssWelcomeHomeNewsHtmlRead(dirname(__DIR__), false);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>var u = new URL(location.href); if (u.username || u.password) { u.username = ''; u.password = ''; location.replace(u.href); }</script>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= pmssWelcomeHtmlAttr($vendor['name']); ?> Seedbox</title>
    <link href="screen.css" rel="stylesheet" type="text/css" media="screen" />
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script type="text/javascript"><?= pmssActionScriptJs() ?></script>
    <?= pmssCustomerAppsCss() ?>
    <script type="text/javascript">function pmssWelcomeTabOpen(hash, link) { try { var tab = window.parent !== window ? window.parent.document.querySelector('a[href="' + hash + '"]') : null; if (tab) { tab.click(); return false; } } catch (e) {} return true; }</script>
    <style type="text/css">
<?= pmssWelcomeHomeCss() ?>
    </style>
</head>
<body class="h-body">
    <div id="pmss-action-notice" role="status" aria-live="polite"></div>
    <main class="h" data-user="<?= pmssWelcomeHtmlAttr($username) ?>">
<?php if ($homeAlertsHtml !== ''): ?>
        <section class="h-alerts" aria-label="Alerts"><?= $homeAlertsHtml ?></section>
<?php endif; ?>
        <section class="h-card h-status" aria-labelledby="h-status-title">
            <div class="h-status-head">
                <h1 id="h-status-title" class="h-label">Your account</h1>
                <div class="h-chips"><?= $homeStatusChipsHtml ?></div>
            </div>
            <div class="h-metrics<?= $homeCreditsKey ? ' h-m5' : '' ?>"><?= $homeMetricsHtml ?></div>
        </section>
<?php if ($pmssPikkumiesState['visible']): ?>
        <div class="h-ask">
            <div class="pmss-pikkumies-bar" id="pmss-pikkumies-bar">
                <button type="button" id="pmss-pikkumies-bar-disabled" aria-disabled="true" title="Click to see what is happening" onclick="pmssPikkumiesTabOpen()">Pikkumies is getting ready…</button>
            </div>
        </div>
<?php endif; ?>
        <div class="h-main">
            <div class="h-col">
                <section class="h-card" aria-labelledby="h-apps-title">
                    <div class="h-head"><h2 id="h-apps-title" class="h-label">Your apps</h2><a class="h-head-link" href="configure.php#apps" onclick="return pmssWelcomeTabOpen('#pmssconfigure', this)">Manage apps</a></div>
                    <?= $homeAppsHtml ?>
                </section>
                <?= $homeConnectHtml ?>
            </div>
            <div class="h-col">
                <section class="h-card" aria-labelledby="h-news-title">
                    <div class="h-head"><h2 id="h-news-title" class="h-label">News</h2></div>
                    <div id="h-news" data-src="welcome.php?news=1"><?= $homeNewsHtml ?></div>
                </section>
<?php if (trim(strip_tags($welcomeHeadingHtml)) !== ''): ?>
                <section class="h-card h-tip" aria-label="Tip"><?= $welcomeHeadingHtml ?></section>
<?php endif; ?>
                <section class="h-card" aria-labelledby="h-help-title">
                    <h2 id="h-help-title" class="h-label">Help</h2>
                    <details class="h-get-help" id="h-get-help">
                        <summary>Get help</summary>
                        <form id="h-support-form" autocomplete="on" onsubmit="return pmssSupportSend(this)" data-pikkumies="<?= !empty($pmssPikkumiesState['visible']) && $homeCreditsKey ? '1' : '0' ?>">
                            <label for="h-support-text">What's wrong?</label>
                            <textarea id="h-support-text" name="text" rows="4" maxlength="8000" required></textarea>
                            <div class="h-support-topics" role="radiogroup" aria-label="Topic">
<?php foreach (pmssWelcomeHomeSupportTopics() as $pmssTopicId => $pmssTopicLabel): ?>
                                <label><input type="radio" name="topic" value="<?= $pmssTopicId ?>"<?= $pmssTopicId === 'working' ? ' checked' : '' ?>><span><?= $pmssTopicLabel ?></span></label>
<?php endforeach; ?>
                            </div>
<?php $pmssSupportEmail = pmssWelcomeHomeSupportEmailDefault(dirname(__DIR__)); ?>
<?php if ($pmssSupportEmail !== ''): ?>
                            <input name="email" type="hidden" value="<?= pmssWelcomeHtmlAttr($pmssSupportEmail) ?>">
<?php else: ?>
                            <label for="h-support-email">Reply to</label>
                            <input id="h-support-email" name="email" type="email" required value="">
<?php endif; ?>
<?php if (!empty($pmssPikkumiesState['visible']) && $homeCreditsKey): ?>
                            <p class="h-muted h-support-note" id="h-support-pikkumies">Not working and Slow: Pikkumies checks first, on your AI credits (about 10,000 to 60,000 a check), and tells you if no ticket is needed. Passwords are removed before anything is sent.</p>
<?php else: ?>
                            <p class="h-muted h-support-note">Passwords are removed before anything is sent.<?= $homeCreditsKey ? '' : ' Opens in the client area.' ?></p>
<?php endif; ?>
                            <div class="h-support-buttons">
                                <button type="submit">Send</button>
<?php if (!empty($pmssPikkumiesState['visible']) && $homeCreditsKey): ?>
                                <button type="button" class="h-support-skip" onclick="return pmssSupportSend(this.form, true)">Send to support instead</button>
<?php endif; ?>
                            </div>
                            <p class="h-support-result" id="h-support-result" role="status" hidden></p>
<?php if (!empty($pmssPikkumiesState['visible']) && $homeCreditsKey): ?>
                            <div class="h-support-activity" id="h-support-activity" hidden><span class="h-muted">Pikkumies:</span> <span id="h-support-activity-line">starting…</span> <button type="button" onclick="pmssPikkumiesTabOpen()">Open Pikkumies</button></div>
<?php endif; ?>
                        </form>
                    </details>
                    <nav class="h-help">
<?php if ((int) $billingServiceId > 0): ?>
                        <a href="https://pulsedmedia.com/clients/clientarea.php?action=productdetails&amp;id=<?= (int) $billingServiceId ?>" target="_blank" rel="noopener">Your service in the client area</a>
<?php endif; ?>
                        <a href="https://pulsedmedia.com/clients/knowledgebase.php" target="_blank" rel="noopener">Knowledge base</a>
                        <a href="https://discord.gg/cGBz52HJtx" target="_blank" rel="noopener">Discord</a>
                        <a href="mailto:support@pulsedmedia.com">support@pulsedmedia.com</a>
                    </nav>
                </section>
            </div>
        </div>
<?php if (!$pmssConfigureAvailable): ?>
        <section class="h-card h-legacy" aria-labelledby="h-legacy-title">
            <h2 id="h-legacy-title" class="h-label">Apps and settings</h2>
                        <h6>Usage alert email</h6>
                        <p>Opt in to email when traffic reaches 80% of the monthly allowance, disk usage reaches 90% of quota, or a monitored media-stack service is reported down. Alerts reset after the condition clears.</p>
                        <input type="button" name="usageAlertsMode" value="<?php echo $usageAlertsEnabled ? 'Disable usage alert email' : 'Enable usage alert email'; ?>" onClick="pmssUsageAlertsModeApply(this, '<?php echo $usageAlertsEnabled ? 'disabled' : 'enabled'; ?>');" />

                        <?php echo pmssCustomerAppsSectionHtmlBuild($appsStatus, 'welcome.php?status=1'); ?>
                        <h6>App passwords</h6>
                        <p>Deluge Web UI password: <b><?php echo pmssCustomerHtmlAttr($delugePassword === '' ? 'Unavailable' : $delugePassword); ?></b> (also used for the daemon connection; separate from your account password)</p>
                        <?php if ($delugePasswordCanRotate): ?><form method="post" action="welcome.php" onsubmit="return pmssWelcomeActionSubmit(this)"><input type="hidden" name="delugePasswordRotate" value="1" /><input type="submit" value="Rotate Deluge Password" /></form><?php else: ?><p><b>Deluge password rotation is unavailable on this host.</b></p><?php endif; ?>
                        <?php if ($delugePasswordNotice !== ''): ?><p><b><?php echo pmssCustomerHtmlAttr($delugePasswordNotice); ?></b></p><?php endif; ?>
                        <p>rclone password is the same as your web access password.</p>
                        <p>qBittorrent username is your own username. Existing setups may use your account password; if the panel prompts you, set a separate WebUI password and use it for future logins.</p>

<?php
if (file_exists('mediaStack.php') && function_exists('pmssMediaStackPanelHtmlBuild')) {
?>
                        <h6>Media Stack</h6>
                        <div id="pmss-media-stack-status"><?php echo pmssMediaStackPanelHtmlBuild($mediaStackStatus); ?></div>
<?php
}
?>

                        <h6>Torrent configuration backup</h6>
                        <p>Download rTorrent, ruTorrent, Deluge, and qBittorrent configuration plus torrent session/resume state. Media files are not included.</p>
                        <p><b>Keep the archive private:</b> it can contain private tracker URLs and separate application credentials.</p>
                        <form method="get" action="welcome.php">
                            <input type="hidden" name="backup" value="download" />
                            <input type="submit" name="configBackupDownload" value="Download torrent configuration backup" />
                        </form>
                        <h6>Torrent configuration restore</h6>
                        <p>Upload the backup with File Manager, stop your torrent client, then restore the uploaded archive path here.</p>
                        <form method="post" action="welcome.php?restore=config" onsubmit="return pmssWelcomeActionSubmit(this)">
                            <input type="text" name="archivePath" value="" placeholder="pmss-torrent-config-YYYYMMDD-HHMMSS.tar.gz" style="width: 360px; max-width: 100%;" />
                            <input type="submit" name="torrentConfigRestore" value="Restore torrent configuration" />
                        </form>
<?php
if (is_array($configRestoreResult)) {
    $restoreColor = !empty($configRestoreResult['ok']) ? '#2e7d32' : '#b71c1c';
?>
                        <p style="color: <?php echo $restoreColor; ?>;"><b><?php echo !empty($configRestoreResult['ok']) ? 'Restore complete:' : 'Restore not run:'; ?></b> <?php echo pmssCustomerHtmlAttr($configRestoreResult['message']); ?></p>
<?php
}
?>
<?php
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

                        <h6>Panel frame source<?php echo pmssCustomerContextualHelpLinkBuild('panelFrameSource'); ?></h6>
                        <p>Remote-updated frame definitions are the default. Local definitions avoid that frame-definition request, but receive navigation changes only with server PMSS updates. Other panel content may still use remote resources.</p>
                        <input type="button" name="guiFramesMode" value="<?php echo $guiFramesLocalOnly ? 'Use remote-updated frames' : 'Use local frame definitions'; ?>" onClick="pmssGuiFramesModeApply(this, '<?php echo $guiFramesLocalOnly ? 'remote' : 'local'; ?>');" />
        </section>
<?php endif; ?>
    </main>

    <script type="text/javascript">
        /* Legacy controls (shown before the Configure tab reaches the server). */
        function pmssGuiFramesModeApply(button, mode) {
            pmssSetActionLoading(button, true);
            pmssShowActionNotice('Saving frame source preference...', false);
            $.ajax({url: 'index.php', type: 'POST', cache: false, headers: {'X-Requested-With': 'XMLHttpRequest'}, data: {guiFramesMode: mode},
                success: function() { window.top.location.reload(true); },
                error: function() { pmssSetActionLoading(button, false); pmssShowActionNotice('Frame source preference could not be saved.', true); }});
        }
        function pmssUsageAlertsModeApply(button, mode) {
            pmssSetActionLoading(button, true);
            pmssShowActionNotice('Saving usage alert preference...', false);
            $.ajax({url: 'index.php', type: 'POST', cache: false, headers: {'X-Requested-With': 'XMLHttpRequest'}, data: {usageAlertsMode: mode},
                success: function() { window.setTimeout(function() { location.reload(true); }, 500); },
                error: function() { pmssSetActionLoading(button, false); pmssShowActionNotice('Usage alert preference could not be saved.', true); }});
        }
        function pmssWelcomeActionSubmit(form) {
            $.ajax({url: form.action, type: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, data: $(form).serialize(),
                success: function(html) { document.open(); document.write(html); document.close(); },
                error: function() { pmssShowActionNotice('Action could not be completed.', true); }});
            return false;
        }
<?php if (!$pmssConfigureAvailable): ?>
        pmssMediaStackStatusRefresh();
<?php endif; ?>

        /* Welcome home. */
        (function () {
            var main = document.querySelector('main.h');
            var user = main ? main.getAttribute('data-user') : '';
            // Every account on a server shares one browser origin, so keys carry the account name.
            function store(key, value) {
                try { if (value === undefined) return localStorage.getItem('pmss:' + user + ':' + key); localStorage.setItem('pmss:' + user + ':' + key, value); }
                catch (e) { return null; }
            }
            // The panel hides this frame's tab (display:none) when another tab is open. The frame's own viewport keeps its
            // size then, so ask the panel (same origin) whether the frame element is still rendered.
            function visible() {
                if (document.hidden) return false;
                try { if (window.frameElement) return window.frameElement.getClientRects().length > 0; } catch (e) {}
                return window.innerHeight > 0 && window.innerWidth > 0;
            }
            window.pmssWelcomeVisible = visible;

            document.addEventListener('click', function (event) {
                var button = event.target && event.target.closest ? event.target.closest('.h-copy') : null;
                if (!button) return;
                var value = button.getAttribute('data-copy');
                function done() { button.textContent = 'Copied'; setTimeout(function () { button.textContent = 'Copy'; }, 1500); }
                if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(value).then(done, function () {}); return; }
                var area = document.createElement('textarea');
                area.value = value; document.body.appendChild(area); area.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(area);
            });

            var news = document.getElementById('h-news');
            function newsMark() {
                var now = Math.floor(Date.now() / 1000), seen = parseInt(store('news-seen') || '0', 10) || now - 7 * 86400;
                var rows = news.querySelectorAll('li[data-ts]');
                for (var i = 0; i < rows.length; i++) {
                    if (parseInt(rows[i].getAttribute('data-ts'), 10) > seen) rows[i].className += ' h-new';
                }
                if (rows.length) store('news-seen', String(now));
            }
            if (news && news.querySelector('.h-news-wait') && window.fetch) {
                fetch(news.getAttribute('data-src'), {credentials: 'same-origin', cache: 'no-store'})
                    .then(function (response) { return response.ok ? response.text() : ''; })
                    .then(function (html) { if (html) { news.innerHTML = html; newsMark(); } })
                    .catch(function () {});
            } else if (news) {
                newsMark();
            }

            var words = {'running': ['Running', 'h-state-run'], 'installing': ['Setting up', 'h-state-warn'], 'not-running': ['Not running', 'h-state-warn'],
                'stopped': ['Stopped', 'h-state-warn'], 'not secured': ['Needs a login', 'h-state-warn'], 'gave up': ['Gave up', 'h-state-bad'], 'failed': ['Failed', 'h-state-bad']};
            function appsRefresh() {
                if (!visible() || !window.fetch || !document.querySelector('.h-app')) return;
                fetch('welcome.php?status=1', {credentials: 'same-origin', cache: 'no-store'})
                    .then(function (response) { return response.json(); })
                    .then(function (status) {
                        var tiles = document.querySelectorAll('.h-app[data-app]');
                        for (var i = 0; i < tiles.length; i++) {
                            var app = status.apps && status.apps[tiles[i].getAttribute('data-app')];
                            var word = app && words[app.state], label = tiles[i].querySelector('[data-state-word]');
                            if (word && label) { label.textContent = word[0]; label.className = 'h-state ' + word[1]; }
                        }
                    }).catch(function () {});
            }
            setInterval(appsRefresh, 30000);

            // Reload for fresh figures only when the customer comes back after a long absence, never while typing.
            var hiddenSince = 0;
            setInterval(function () {
                if (!visible()) { if (!hiddenSince) hiddenSince = Date.now(); return; }
                var away = hiddenSince ? Date.now() - hiddenSince : 0, active = document.activeElement;
                hiddenSince = 0;
                if (away > 600000 && !(active && /^(INPUT|TEXTAREA|SELECT|IFRAME)$/.test(active.tagName))) location.reload();
            }, 15000);
        })();
<?php if ($pmssPikkumiesState['visible']): ?>

        /* Pikkumies ask bar. */
        var pmssPikkumiesBarOrigin = <?= json_encode($pmssPikkumiesState['origin']) ?>;
        var pmssPikkumiesBarReady = false, pmssPikkumiesBarInstalled = false, pmssPikkumiesBarTimer = 0;
        function pmssPikkumiesTabOpen() {
            try {
                var tab = window.parent.document.querySelector('a[href="#pikkumies"]');
                if (tab) { tab.click(); return; }
                window.top.location.reload(true);
            } catch (e) { location.href = 'pikkumiesOpen.php?session=pm1'; }
        }
        function pmssPikkumiesBarPoll() {
            if (pmssPikkumiesBarInstalled) { clearInterval(pmssPikkumiesBarTimer); return; }
            if (!window.pmssWelcomeVisible()) return;
            fetch('pikkumiesOpen.php?view=status', {credentials:'same-origin',cache:'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (state) {
                    if (!state.visible) { document.getElementById('pmss-pikkumies-bar').hidden = true; clearInterval(pmssPikkumiesBarTimer); return; }
                    if (!state.ready || pmssPikkumiesBarInstalled) return;
                    pmssPikkumiesBarInstalled = true;
                    clearInterval(pmssPikkumiesBarTimer);
                    var frame = document.createElement('iframe');
                    frame.id = 'pmss-pikkumies-bar-frame';
                    frame.title = 'Ask Pikkumies';
                    frame.setAttribute('aria-label', 'Ask Pikkumies');
                    frame.src = pmssPikkumiesBarOrigin + '/bar';
                    frame.addEventListener('focus', function () {
                        if (window.matchMedia('(pointer:fine)').matches)
                            window.parent.postMessage('pmss:pikkumies-preload', location.origin);
                    });
                    var wrap = document.getElementById('pmss-pikkumies-bar');
                    wrap.replaceChild(frame, document.getElementById('pmss-pikkumies-bar-disabled'));
                    setTimeout(function () { if (!pmssPikkumiesBarReady) wrap.hidden = true; }, 6000);
                    if (window.matchMedia('(pointer:fine)').matches && !sessionStorage.getItem('pmss-pikkumies-bar-focused')) {
                        sessionStorage.setItem('pmss-pikkumies-bar-focused', '1'); frame.focus();
                    }
                }).catch(function () {});
        }
        window.addEventListener('message', function (event) {
            var frame = document.getElementById('pmss-pikkumies-bar-frame');
            if (event.origin === pmssPikkumiesBarOrigin && frame && event.source === frame.contentWindow) {
                if (event.data === 'pikkumies:ready') pmssPikkumiesBarReady = true;
                if (event.data === 'pikkumies:switch'
                    && !window.parent.document.querySelector('a[href="#pikkumies"]')) window.top.location.reload(true);
            }
            if (event.origin === location.origin && event.source === window.parent
                && event.data === 'pikkumies:bar-ready') pmssPikkumiesBarReady = true;
            if (event.origin === location.origin && event.source === window.parent
                && event.data === 'pikkumies:tab-ready' && !pmssPikkumiesBarReady && frame) frame.src = pmssPikkumiesBarOrigin + '/bar';
        });
        pmssPikkumiesBarPoll(); pmssPikkumiesBarTimer = setInterval(pmssPikkumiesBarPoll, 5000);
<?php endif; ?>
<?php if ($homeCreditsKey): ?>

        /* AI credits: the status-strip card (ADR 0148). */
        (function () {
            var card = document.getElementById('h-credits');
            if (!card || !window.fetch) return;
            fetch('welcome.php?credits=1', {credentials: 'same-origin', cache: 'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (credits) {
                    if (credits.state !== 'ok') {
                        var strip = card.parentNode;
                        card.parentNode.removeChild(card);
                        if (strip) strip.className = strip.className.replace(/\s*h-m5\b/, '');
                        return;
                    }
                    card.querySelector('[data-credits-value]').textContent = credits.value;
                    card.querySelector('[data-credits-meta]').textContent = credits.meta;
                    if (credits.pct_left !== null) {
                        var fill = document.createElement('i');
                        fill.className = credits.pct_left < 10 ? 't-warn' : 's-base';
                        fill.style.width = Math.max(0, Math.min(100, credits.pct_left)).toFixed(2) + '%';
                        var meter = card.querySelector('[data-credits-bar]');
                        meter.className = 'h-bar';
                        meter.appendChild(fill);
                    }
                }).catch(function () {});
        })();
<?php endif; ?>

        /* App tiles (operator 2026-10-23: "single click installs and management"): Start and Restart call the same endpoints
           as Configure's Apps list, through its request helper (XHR header, consent prompts, the qBittorrent password). */
        function pmssAppsMoreToggle(button) {
            var open = document.getElementById('h-apps').classList.toggle('h-apps-all');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.textContent = open ? 'Fewer apps' : button.textContent.replace('Fewer apps', 'More apps');
        }
        $(document).on('click', '.h-app[data-app] button[data-tile-action]', function () {
            var button = this, tile = $(button).closest('.h-app'), id = tile.attr('data-app'), action = $(button).attr('data-tile-action');
            var managed = window.pmssManagedAppEndpoints && pmssManagedAppEndpoints[id], kind = id === 'rtorrent' ? 'rtorrent' : (managed ? 'managed' : 'media');
            if (kind === 'managed' && action === 'start' && pmssManagedAppConsent[id] && !window.confirm(pmssManagedAppConsent[id])) return;
            var url = kind === 'media' ? 'mediaStack.php?action=app-' + action : (kind === 'rtorrent' ? 'rtorrentRestart.php?action=' + action : managed + (managed.indexOf('?') >= 0 ? '&action=' : '?action=') + action);
            var note = tile.find('.h-app-note');
            tile.find('button').prop('disabled', true); note.text(action === 'restart' ? 'Restarting…' : 'Starting…');
            pmssActionRequest({url: url, data: kind === 'media' ? {app: id} : null, passwordField: id === 'qBittorrent' ? 'qbittorrentPassword' : ''}).done(function () {
                var deadline = Date.now() + ((window.pmssManagedAppWaitSeconds && pmssManagedAppWaitSeconds[id]) || 60) * 1000;
                (function wait() {
                    window.setTimeout(function () {
                        $.getJSON('welcome.php?status=1', function (status) {
                            var state = status.apps && status.apps[id] && status.apps[id].state;
                            if (state === 'running') { location.reload(); return; }
                            if (Date.now() < deadline) { wait(); return; }
                            note.text('It has not started yet. Check it in Configure.'); tile.find('button').prop('disabled', false);
                        }).fail(function () { if (Date.now() < deadline) wait(); else { note.text('Could not read the app status.'); tile.find('button').prop('disabled', false); } });
                    }, 3000);
                })();
            }).fail(function (xhr, cancelled) {
                tile.find('button').prop('disabled', false);
                note.text(cancelled ? '' : ((xhr && xhr.responseJSON && xhr.responseJSON.message) || 'That did not work. Try again, or open Configure.'));
            });
        });
        /* Get help (ADR 0149): a ticket through the account's AI key (no credits spent); billing and accounts without a key go
           to the client area. Where Pikkumies runs, Not working and Slow go to Pikkumies first unless the customer skips it. */
        function pmssSupportSend(form, skip) {
            var result = document.getElementById('h-support-result'), button = form.querySelector('button[type=submit]');
            var topic = (form.querySelector('input[name=topic]:checked') || {}).value || 'working';
            var clientArea = 'https://pulsedmedia.com/clients/submitticket.php';
            var show = function (text, link) {
                result.textContent = text;
                if (link) { var a = document.createElement('a'); a.href = clientArea; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ' Open the client area form'; result.appendChild(a); }
                result.hidden = false;
            };
            if (topic === 'billing' || <?= $homeCreditsKey ? 'false' : 'true' ?>) { show('This one goes through the client area.', true); return false; }
            if (form.reportValidity && !form.reportValidity()) return false;
            var viaPikkumies = !skip && form.getAttribute('data-pikkumies') === '1' && (topic === 'working' || topic === 'slow');
            var fields = {topic: topic, text: form.text.value, email: form.email.value};
            if (viaPikkumies) fields.nonce = pmssSupportNonce();
            button.disabled = true; show(viaPikkumies ? 'Handing it to Pikkumies…' : 'Sending…', false);
            fetch('welcome.php?support=' + (viaPikkumies ? 'pikkumies' : '1'), {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams(fields)})
                .then(function (response) { return response.json(); })
                .then(function (answer) {
                    show(answer.message || 'Support could not be reached just now.', !answer.ok && !answer.pikkumies);
                    if (answer.ok && answer.pikkumies) pmssSupportActivityWatch(answer.sent);
                    else if (answer.ok) form.text.value = '';
                })
                .catch(function () { show('Support could not be reached just now.', true); })
                .then(function () { button.disabled = false; });
            return false;
        }
        function pmssSupportNonce() {
            var bytes = new Uint8Array(16), hex = '';
            (window.crypto || window.msCrypto).getRandomValues(bytes);
            for (var i = 0; i < bytes.length; i++) hex += ('0' + bytes[i].toString(16)).slice(-2);
            return hex;
        }
        /* While Pikkumies works: its state and its own latest words (never a command), every 4 s for up to 15 minutes; at
           the end its Result line when it gave one. A question Pikkumies never starts on (e.g. the credit service refused
           it) says so after about a minute of answered polls. */
        function pmssSupportActivityWatch(sent) {
            var box = document.getElementById('h-support-activity'), line = document.getElementById('h-support-activity-line');
            if (!box || !line || box.getAttribute('data-watching') === '1') return;
            box.setAttribute('data-watching', '1'); box.hidden = false; line.textContent = 'is starting…';
            var started0 = Date.now(), sentAt = (sent || Math.floor(started0 / 1000)) - 2, seenBusy = false, polls = 0, timer = setInterval(function () {
                if (Date.now() - started0 > 15 * 60 * 1000) { clearInterval(timer); box.removeAttribute('data-watching'); return; }
                if (window.pmssWelcomeVisible && !window.pmssWelcomeVisible()) return;
                fetch('welcome.php?support=activity', {credentials: 'same-origin', cache: 'no-store'})
                    .then(function (response) { return response.json(); })
                    .then(function (activity) {
                        if (activity.state === 'busy') seenBusy = true;
                        var started = activity.at >= sentAt, said = activity.said && activity.said_at >= sentAt ? activity.said : '';  /* earlier questions are not this one */
                        /* Done: idle after a busy poll, or already idle with this question's activity (a quick answer can finish between polls). */
                        if ((seenBusy && (activity.state === 'idle' || activity.state === 'held')) || (activity.state === 'idle' && started)) {
                            line.textContent = said.indexOf('Result:') === 0 ? said.replace(/^Result:\s*/, '') + ' More in the Pikkumies tab.' : 'is done. Its answer is in the Pikkumies tab.';
                            clearInterval(timer); box.removeAttribute('data-watching'); return;
                        }
                        if (!seenBusy && !started && ++polls >= 15) {
                            line.textContent = 'has not started on it. Open Pikkumies to see why, or send this to support now.';
                            clearInterval(timer); box.removeAttribute('data-watching'); return;
                        }
                        line.textContent = said !== '' ? said : (seenBusy || started ? 'is checking your seedbox… (' + Math.round((Date.now() - started0) / 1000) + ' s)' : 'is starting…');
                    }).catch(function () {});
            }, 4000);
        }
    </script>
</body>
</html>
<?php
/** Keep the healed Welcome page usable before the update-only helper arrives. */
function pmssWelcomePikkumiesStateRead($home, $username)
{
    return function_exists('pmssPikkumiesPanelStateRead') && is_file(__DIR__.'/pikkumiesOpen.php')
        ? pmssPikkumiesPanelStateRead($home, $username)
        : array('visible' => false, 'origin' => '');
}

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
        'isReduced' => $throttleEnforced,
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
 * Link traffic upgrades to the service when billing identifies it.
 */
function pmssWelcomeTrafficUpgradeUrlBuild($billingServiceId) {
    if ((int) $billingServiceId <= 0) {
        return 'https://pulsedmedia.com/clients/knowledgebase.php?action=displayarticle&id=36';
    }

    return 'https://pulsedmedia.com/clients/upgrade.php?type=configoptions&id='.(int) $billingServiceId;
}

/**
 * Keep the traffic add-on destination consistent across status messages.
 */
function pmssWelcomeTrafficUpgradeLinkHtmlBuild($billingServiceId) {
    return '<a href="'.pmssCustomerHtmlAttr(pmssWelcomeTrafficUpgradeUrlBuild($billingServiceId)).'" target="_blank">Add extra traffic to your plan</a>';
}

/**
 * Build tiny traffic-cap disclosure text shown near usage gauge.
 */
function pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId) {
    $effectiveCapMbit = is_numeric($trafficBandwidthState['effectiveCapMbit'] ?? null)
        ? (int) $trafficBandwidthState['effectiveCapMbit']
        : 0;
    $isReduced = ($trafficBandwidthState['isReduced'] ?? null) === true;
    if (!$isReduced) {
        return '<span style="font-size: 0.82em; color: #666;">Current effective: full plan port speed</span>';
    }

    $effectiveText = number_format(max(0, $effectiveCapMbit));

    return '<span style="font-size: 0.82em; color: #7a1a1a;">Current effective: '.$effectiveText.' Mbps (reduced)</span>'
        . '<br /><span style="font-size: 0.82em;"><a href="'.pmssCustomerHtmlAttr(pmssWelcomeTrafficUpgradeUrlBuild($billingServiceId)).'" target="_blank">Need more bandwidth? Upgrade your plan.</a></span>';
}

/**
 * Build customer-visible traffic throttle status for capped accounts.
 */
function pmssWelcomeTrafficDisclosureHtmlBuild($trafficPercent, $trafficBandwidthState, $billingServiceId, $overCap) {
    $usageText = number_format((float) $trafficPercent, 1);
    $isReduced = !empty($trafficBandwidthState['isReduced']);
    // An absent throttle marker does not make over-cap usage a full-speed promise.
    $lines = array();
    if (!$overCap || $isReduced) {
        $lines[] = pmssWelcomeTrafficEffectiveHtmlBuild($trafficBandwidthState, $billingServiceId);
    }
    $lines[] = 'Usage: <b>'.$usageText.'%</b> of monthly traffic cap.';

    $effectiveCapMbit = is_numeric($trafficBandwidthState['effectiveCapMbit'] ?? null)
        ? max(0, (int) $trafficBandwidthState['effectiveCapMbit'])
        : 0;
    $throttleFileMtime = is_numeric($trafficBandwidthState['throttleFileMtime'] ?? null)
        ? (int) $trafficBandwidthState['throttleFileMtime']
        : null;
    $capText = number_format($effectiveCapMbit).' Mbps';

    if ($isReduced && $overCap) {
        $overagePercent = max(0.0, (float) $trafficPercent - 100.0);
        $lines[] = '<b>Throttled to '.$capText.'</b> - usage is '.number_format($overagePercent, 1).'% over cap. Throttle lift requires 3 consecutive days under cap.';
    } elseif ($isReduced) {
        $cooldown = pmssWelcomeTrafficCooldownTextBuild($throttleFileMtime);
        $lines[] = '<b>Throttle cooldown active</b> - current ceiling is '.$capText.'; '.$cooldown;
    } elseif ($overCap) {
        $overagePercent = max(0.0, (float) $trafficPercent - 100.0);
        $lines[] = '<b>Over monthly traffic cap</b> - usage is '.number_format($overagePercent, 1).'% over cap.';
    } elseif ((float) $trafficPercent >= 70.0) {
        $lines[] = '<b>Approaching monthly traffic cap</b> - review the traffic throttle policy before crossing the cap.';
    }

    if (!$overCap && ($isReduced || (float) $trafficPercent >= 70.0)) {
        $lines[] = pmssWelcomeTrafficUpgradeLinkHtmlBuild($billingServiceId).'.';
    }
    $lines[] = 'Policy: <a href="https://wiki.pulsedmedia.com/wiki/Pulsed_Media_Traffic_Limits#How_the_throttle_works" target="_blank">How the throttle works</a>.';
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

/** Format server uptime without rounding it beyond the observed duration. */
function pmssWelcomeServerUptimeTextBuild($seconds) {
    $seconds = pmssCustomerNonnegativeFloat($seconds);
    if ($seconds === null) return null;
    $seconds = (int) floor($seconds);
    if ($seconds < 60) return 'under 1 minute';

    $values = array(
        'day' => (int) floor($seconds / 86400),
        'hour' => (int) floor(($seconds % 86400) / 3600),
        'minute' => (int) floor(($seconds % 3600) / 60),
    );
    $parts = array();
    foreach ($values as $unit => $value) {
        if ($value <= 0) continue;
        $parts[] = $value.' '.$unit.($value === 1 ? '' : 's');
        if (count($parts) === 2) break;
    }

    return implode(' ', $parts);
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
    if (!is_array($vendor) || empty($vendor) || empty($vendor['name'])) {
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

function pmssWelcomeStorageHealthNoticeHtmlRead() {
    // Customer-side storage-health notice (see storageHealthNotice.php).
    if (!pmssWelcomeRequireLocalHelper('storageHealthNotice.php')) {
        return '';
    }

    if (function_exists('pmssStorageHealthNoticeHtmlRead')) {
        return pmssStorageHealthNoticeHtmlRead();
    }

    // Preserve the RAID notice during staggered guiv delivery of the helper.
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
        pmssFrontendPostActionRequired();
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

function pmssWelcomeHeadingHtmlBuild($contextualWelcomeMessage) {
    $html = '';
    $welcomeHeading = pmssWelcomeHomeCachedFetch(dirname(__DIR__), 'https://pulsedmedia.com/remote/welcomeHeadingText.php');
    if ($welcomeHeading !== false) {
        $html .= $welcomeHeading;
    }

    if (is_string($vendorWelcome = pmssCustomerFileRead('/etc/seedbox/config/vendorWelcome', true))) {
        $html .= $vendorWelcome;
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
        '/data/v1/panel-feed.xml',
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
    return is_array($userConfig) && is_numeric($userConfig[$key] ?? null)
        ? (float) $userConfig[$key]
        : null;
}

function pmssWelcomeSerializedArrayRead($path) {
    return pmssCustomerSerializedArrayFileRead($path, 1048576);
}

function pmssWelcomeTrafficMonthValueRead($trafficState) {
    return is_array($trafficState) && is_array($trafficState['raw'] ?? null)
        ? pmssCustomerNonnegativeFloat($trafficState['raw']['month'] ?? null)
        : null;
}


function createStackedGauge($titleText, $footerText, $percent, $segments) {
    if (!is_finite($percent)) $percent = 0;
    $barHtml = '';
    $filledPercent = 0;
    foreach ($segments as $segment) {
        $width = is_numeric($segment['width'] ?? null) ? (float) $segment['width'] : 0;
        if (empty($segment['raw'])) {
            $width = max(0, min(100 - $filledPercent, $width));
            if ($width <= 0) {
                continue;
            }
            $filledPercent += $width;
        }
        $color = (string) ($segment['color'] ?? 'transparent');
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



/** Welcome styles. Inline because the guiv heal delivers welcome.php but not screen.css (ADR 0022). */
function pmssWelcomeHomeCss()
{
    return <<<'CSS'
:root{--bg:#0d131b;--surface:#141c27;--surface-2:#1a2433;--line:#243143;--text:#e6edf3;--muted:#9aa9bc;--faint:#7a899c;--accent:#4fc3f7;--ok:#3fb950;--warn:#d29922;--bad:#f85149;color-scheme:dark}
.h-body{margin:0;background:var(--bg);color:var(--text);font:13px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif}
.h-body *{box-sizing:border-box}
.h a{color:var(--accent);text-decoration:none}.h a:hover{text-decoration:underline}
.h :focus-visible{outline:2px solid #9dd8f5;outline-offset:2px;border-radius:6px}
.h{max-width:1280px;margin:0 auto;padding:12px 14px 24px}
.h-label{margin:0;color:var(--muted);font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.h-card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:12px 14px;min-width:0}
.h-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:10px}
.h .h-head-link{font-size:12px}
.h-muted{color:var(--muted)}.h-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.h code{font:12px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--text)}
/* Alerts: only when something needs attention */
.h-alerts{display:grid;gap:8px;margin-bottom:10px}
.h-alert,.h-alerts .pmss-raid-notice{margin:0;padding:10px 14px;border:1px solid var(--line);border-left:3px solid var(--warn);border-radius:8px;background:var(--surface-2);color:var(--text)}
.h-alert-bad,.h-alerts .pmss-raid-notice-error{border-left-color:var(--bad)}
.h-alert strong,.h-alerts .pmss-raid-notice strong{display:block;margin-bottom:2px}
.h-alert p,.h-alerts .pmss-raid-notice p{margin:3px 0 0;color:var(--muted)}
.h-alerts .pmss-raid-icon{color:var(--warn);margin-right:6px}.h-alerts .pmss-raid-meta{color:var(--text)}
.h-alert .pmss-traffic-disclosure{margin:4px 0 0;padding:0;border:0;background:none;color:var(--muted)}
.h-alert .pmss-traffic-disclosure span{color:inherit!important;font-size:inherit!important}
/* Status strip: the quick stats, with the bonus among them */
.h-status{margin-bottom:10px}
.h-status-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:6px 12px;margin-bottom:10px}
.h-chips{display:flex;flex-wrap:wrap;gap:6px}
.h .h-chip{display:inline-flex;align-items:center;gap:5px;min-height:26px;padding:3px 10px;border:1px solid var(--line);border-radius:999px;background:var(--surface-2);color:var(--text);font-size:12px}
.h .h-chip:hover{border-color:#35506a;text-decoration:none}
.h-chip b{font-weight:600}.h-chip-bonus b{color:var(--accent)}
.h-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.h-metrics.h-m5{grid-template-columns:repeat(5,minmax(0,1fr))}
.h-metric{min-width:0;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface-2)}
.h-metric-l{color:var(--muted);font-size:12px}
.h-metric-v{margin:1px 0 7px;font-size:18px;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.h-metric-v span{margin-left:5px;color:var(--muted);font-size:12px;font-weight:400}
.h-bar{display:flex;height:6px;overflow:hidden;border-radius:3px;background:#0b1119}
.h-bar i{display:block;height:100%}.h-bar-none{background:transparent}
.h-bar .s-base{background:var(--accent)}.h-bar .s-cache{background:#4b5b70}.h-bar .t-warn{background:var(--warn)}.h-bar .t-bad{background:var(--bad)}
.h-metric-m{margin-top:6px;color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums;line-height:1.4}
.h-metric-m b{color:var(--warn);font-weight:600}
/* Ask bar (Pikkumies) */
.h-ask{margin-bottom:10px}
.pmss-pikkumies-bar{box-sizing:border-box;max-width:100%;color-scheme:dark}
.pmss-pikkumies-bar button,.pmss-pikkumies-bar iframe{box-sizing:border-box;display:block;width:100%;height:52px}
.pmss-pikkumies-bar button{border:1px solid #2e3b4f;border-radius:8px;background:#000;color:#8b949e;padding:0 14px;text-align:left;font:16px system-ui;cursor:pointer}
.pmss-pikkumies-bar button:focus-visible{outline:2px solid #9dd8f5;outline-offset:2px}
.pmss-pikkumies-bar iframe{border:0;background:transparent}
/* Main grid */
.h-main{display:grid;grid-template-columns:minmax(0,1.75fr) minmax(0,1fr);gap:10px;align-items:start}
.h-col{display:grid;gap:10px;min-width:0}
/* Apps */
.h-apps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.h-app{display:flex;flex-direction:column;gap:5px;min-width:0;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface-2)}
.h-app-top{display:flex;align-items:center;gap:8px;min-width:0}
.h-app-n{flex:1;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.h-app-d{color:var(--muted);font-size:12px;line-height:1.35}
.h-apps-empty{margin:0 0 8px}.h-app-ghost{border-style:dashed;background:transparent}.h-app-ghost .h-app-n{color:var(--muted);font-weight:500}.h-app-ghost .h-ico{stroke:var(--faint)}
.h-app-more{display:none}.h-apps-all .h-app-more{display:flex}.h-app-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:auto}.h-app-acts .h-btn{margin-top:0}.h-app-note{color:var(--muted);font-size:12px;min-height:0}.h-app-note:empty{display:none}
.h-apps-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}
.h-app-hero{grid-column:1/-1;flex-direction:row;align-items:center;gap:14px;border-color:#2c4a63;background:linear-gradient(90deg,#16283a,var(--surface-2))}
.h-app-hero .h-app-top{flex:0 0 auto;min-width:200px}.h-app-hero .h-app-d{flex:1}.h-app-hero .h-btn{margin-top:0}
.h-ico{flex:0 0 auto;width:18px;height:18px;fill:none;stroke:var(--accent);stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.h-state{display:inline-flex;align-items:center;gap:5px;color:var(--muted);font-size:11px;white-space:nowrap}
.h-state::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--faint)}
.h-state-run::before{background:var(--ok)}.h-state-warn::before{background:var(--warn)}.h-state-bad::before{background:var(--bad)}
.h .h-btn{display:inline-flex;align-items:center;justify-content:center;min-height:28px;margin-top:auto;padding:4px 14px;border:1px solid #2c4a63;border-radius:7px;background:#13293d;color:#f8fafc;font-size:12px;font-weight:600;align-self:flex-start}
.h .h-btn:hover{background:#18354f;text-decoration:none}
.h .h-btn-quiet{background:transparent;border-color:var(--line);color:var(--text);font-weight:500}
/* Try next */
.h-try-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 14px;margin:0;padding:0;list-style:none}
.h-try-list li{display:flex;gap:8px;align-items:flex-start;color:var(--muted);font-size:12px;line-height:1.4}
.h-try-list b{color:var(--text);font-weight:600}
/* Connect */
.h-kv{display:grid;grid-template-columns:max-content minmax(0,1fr) max-content;gap:6px 12px;align-items:center;margin:0}
.h-kv dt{color:var(--muted);font-size:12px}
.h-kv dd{margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font:12.5px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.h .h-copy{min-height:24px;padding:2px 10px;border:1px solid var(--line);border-radius:6px;background:transparent;color:var(--muted);font-size:12px;cursor:pointer}
.h .h-copy:hover{color:var(--text);border-color:#35506a}
.h-foot{margin:10px 0 0;color:var(--muted);font-size:12px}
/* What's new */
.h-news{list-style:none;margin:0;padding:0}
.h-get-help{margin:0 0 10px}.h-get-help summary{display:inline-block;cursor:pointer;background:var(--accent);color:#06121b;font-weight:600;border-radius:7px;padding:6px 12px;list-style:none}.h-get-help summary::-webkit-details-marker{display:none}
.h-get-help form{display:grid;gap:6px;margin-top:10px}.h-get-help label{color:var(--muted);font-size:12px}.h-get-help textarea,.h-get-help input[type=email]{box-sizing:border-box;width:100%;background:#0b1118;border:1px solid var(--line);border-radius:6px;color:var(--text);padding:7px 8px;font:inherit}
.h-support-topics{display:flex;flex-wrap:wrap;gap:6px}.h-support-topics label{display:inline-flex;align-items:center;gap:5px;padding:3px 10px 3px 6px;border:1px solid var(--line);border-radius:999px;color:var(--text);font-size:13px;cursor:pointer}.h-support-topics label:has(input:checked){border-color:var(--accent);background:#10212f}.h-support-buttons{display:flex;flex-wrap:wrap;gap:8px}.h-support-note{margin:0;font-size:12px}.h-get-help button{justify-self:start;background:var(--accent);color:#06121b;border:0;border-radius:7px;padding:6px 14px;font-weight:600;cursor:pointer}.h-support-result{margin:0}.h-support-result a{margin-left:4px}.h-get-help .h-support-skip,.h-support-activity button{background:transparent;color:var(--accent);border:1px solid var(--line);font-weight:500}.h-support-activity{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;font-size:13px}.h-support-activity[hidden]{display:none}#h-support-activity-line{flex:1 1 200px;min-width:0;overflow-wrap:anywhere}
.h-news li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:baseline;padding:5px 0;border-top:1px solid var(--line)}
.h-news-part+.h-news-part{margin-top:12px}.h-news-sub{margin:0 0 4px;color:var(--faint);font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}.h-news-sub a{margin-left:6px;font-weight:400;text-transform:none;letter-spacing:0}
.h-news li:first-child{border-top:0;padding-top:0}
.h-tag{color:var(--faint);font-size:10.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
.h .h-news a{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text)}
.h .h-news a:hover{color:var(--accent)}
.h-news time{color:var(--faint);font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap}
.h-news .h-new a{color:#fff}
.h-news .h-new a::before{content:"";display:inline-block;width:6px;height:6px;margin:0 6px 2px 0;border-radius:50%;background:var(--accent)}
.h-news-legacy{margin:0;padding-left:16px}.h-news-legacy li{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Tip: the remote message slot, kept quiet whatever markup it carries */
.h-tip{color:var(--muted);font-size:12.5px;line-height:1.5}
.h-tip h1,.h-tip h2,.h-tip h3,.h-tip h4,.h-tip h5,.h-tip h6{display:block;margin:0 0 3px;color:var(--text)!important;font-size:12.5px;font-weight:600;text-transform:none;letter-spacing:0}
.h-tip p{margin:0}
.h-help{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:8px;font-size:12.5px}
/* Legacy controls (before the Configure tab reaches the server, ADR 0103) */
.h-legacy{margin-top:10px}.h-legacy h6{margin:12px 0 4px;color:var(--muted);font-size:11px;letter-spacing:.06em;text-transform:uppercase}
.h-legacy p{margin:0 0 6px}
#pmss-action-notice{display:none;position:fixed;top:10px;right:10px;z-index:9999;max-width:580px;padding:8px 12px;border:1px solid #2c4a63;border-radius:8px;background:#13293d;color:#e6edf3;font-weight:600}
#pmss-action-notice.pmss-error{border-color:#7a2a2a;background:#2a1414;color:#ffd7d7}
.pmss-action-loading{margin-left:6px;color:var(--muted);font-size:.9em}
.pmss-media-stack-box{margin:8px 0 12px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface-2)}
.pmss-media-stack-box ul{margin:8px 0 0 18px}.pmss-media-stack-apps{margin-left:0!important;padding-left:0;list-style:none}.pmss-media-stack-apps li{margin-top:6px}
.pmss-media-stack-auth-badge{display:inline-block;min-width:64px;margin:0 6px;padding:2px 6px;border:1px solid var(--line);border-radius:4px;font-weight:600;text-align:center}
.pmss-media-stack-auth-badge-protected{color:var(--ok)}.pmss-media-stack-auth-badge-exposed{color:var(--bad)}
.pmss-media-stack-box pre{margin-top:10px;max-height:220px;overflow:auto;padding:10px;white-space:pre-wrap;word-break:break-word}
.pmss-feed-row{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media (max-width:1099px){.h-main{grid-template-columns:minmax(0,1fr)}.h-metrics,.h-metrics.h-m5{grid-template-columns:repeat(2,minmax(0,1fr))}.h-m5 .h-metric:last-child{grid-column:1/-1}}
@media (max-width:599px){.h{padding:10px 10px 22px}.h-metrics{gap:8px}.h-metric{padding:9px 10px}.h-metric-v{font-size:16px}
.h-app-hero{flex-direction:column;align-items:flex-start}.h-app-hero .h-app-top{min-width:0}.h-kv{grid-template-columns:max-content minmax(0,1fr)}.h-kv .h-copy{display:none}.h-kv dd{white-space:normal;word-break:break-all}.h-metric-v{white-space:normal}.h-metric-v span{display:block;margin:2px 0 0}}
@media (max-width:599px){.h-apps{grid-template-columns:minmax(0,1fr)}}
@media (prefers-reduced-motion:reduce){.h-body *{transition:none!important;animation:none!important}}
CSS;
}

/* ---------------------------------------------------------------------------------------------
 * Welcome home (ADR 0147): status strip, apps, connect, what's new. View helpers only: every
 * figure comes from the readers above (quota snapshot, traffic files, cgroup memory, app status).
 * ------------------------------------------------------------------------------------------- */

/** Remote content is fetched at most every 15 minutes per account and served stale when the fetch fails. */
function pmssWelcomeHomeCachedFetch($home, $url, $maxAge = 900, $allowNetwork = true)
{
    $dir = rtrim((string) $home, '/').'/.cache/pmss-welcome';
    $file = $dir.'/'.substr(sha1((string) $url), 0, 16).'.cache';
    $cached = (!is_link($file) && is_file($file)) ? @file_get_contents($file, false, null, 0, 1048576) : false;
    $age = is_string($cached) ? time() - (int) @filemtime($file) : PHP_INT_MAX;
    if (is_string($cached) && $age >= 0 && $age < $maxAge) return $cached;
    if (!$allowNetwork) return is_string($cached) ? $cached : false;
    $body = pmssWelcomeRemoteFetch($url);
    if ($body === false) return is_string($cached) ? $cached : false;
    if ((is_dir($dir) && !is_link($dir)) || (!file_exists($dir) && @mkdir($dir, 0700, true))) {
        $tmp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, $body) === strlen($body)) @rename($tmp, $file); else @unlink($tmp);
    }
    return $body;
}

/** Whether the cached copy of every news source is fresh enough to render without the network. */
function pmssWelcomeHomeNewsCacheFresh($home)
{
    foreach (array('https://pulsedmedia.com/clients/announcementsrss.php', 'https://pulsedmedia.com/data/v1/panel-feed.xml') as $url) {
        $file = rtrim((string) $home, '/').'/.cache/pmss-welcome/'.substr(sha1($url), 0, 16).'.cache';
        if (is_link($file) || !is_file($file) || time() - (int) @filemtime($file) >= 900) return false;
    }
    return true;
}

/** News in two parts (operator 2026-10-22: "Maybe two segments, announcements and vainamoinen articles ... like 10 of
 *  them. Wiki updates, probably not needed"): the newest announcements and the newest articles, each newest first. */
function pmssWelcomeHomeNewsSelect(array $announcements, array $articles, $announcementLimit = 5, $articleLimit = 10)
{
    $newest = function (array $items, $limit) {
        usort($items, function ($a, $b) { return $b['time'] - $a['time']; });
        return array_slice($items, 0, $limit);
    };
    return array('announcements' => $newest($announcements, $announcementLimit), 'articles' => $newest($articles, $articleLimit));
}

/** One news part: a small heading, an optional "all" link, one-line rows (data-ts drives the unread dot). Dates only where
 *  recency is the point: announcements yes, articles no (operator 2026-09: "not really much more than the title"). */
function pmssWelcomeHomeNewsPartHtmlBuild($heading, array $rows, $allUrl = '', $dates = true)
{
    if ($rows === array()) return '';
    $html = '<div class="h-news-part"><h3 class="h-news-sub">'.$heading
        .($allUrl !== '' ? ' <a href="'.pmssWelcomeHtmlAttr($allUrl).'" target="_blank" rel="noopener">All</a>' : '').'</h3><ul class="h-news">';
    foreach ($rows as $row) {
        $html .= '<li data-ts="'.(int) $row['time'].'"><a href="'.pmssWelcomeHtmlAttr($row['link']).'" target="_blank" rel="noopener" title="'.pmssWelcomeHtmlAttr($row['title']).'">'
            .pmssWelcomeHtmlAttr($row['title']).'</a>'
            .($dates ? '<time datetime="'.gmdate('Y-m-d', $row['time']).'">'.gmdate('j M', $row['time']).'</time>' : '').'</li>';
    }
    return $html.'</ul></div>';
}
/** Render What's new from the account cache; with $allowNetwork the stale sources are refetched first. */
function pmssWelcomeHomeNewsHtmlRead($home, $allowNetwork)
{
    if (!function_exists('pmssWelcomeFeedItemsRead')) {
        // The heal can deliver this page before the helper that parses feeds into rows: keep the old list.
        $legacy = pmssWelcomeAnnouncementItemsHtmlBuild();
        return $legacy === '' ? '' : '<ul class="h-news-legacy">'.$legacy.'</ul>';
    }
    if (!$allowNetwork && !pmssWelcomeHomeNewsCacheFresh($home)) {
        return '<p class="h-muted h-news-wait">Loading the latest news…</p>';
    }
    $ann = pmssWelcomeHomeCachedFetch($home, 'https://pulsedmedia.com/clients/announcementsrss.php', 900, $allowNetwork);
    $feed = pmssWelcomeHomeCachedFetch($home, 'https://pulsedmedia.com/data/v1/panel-feed.xml', 900, $allowNetwork);
    $news = pmssWelcomeHomeNewsSelect(
        is_string($ann) ? pmssWelcomeFeedItemsRead($ann, '', 10) : array(),
        is_string($feed) ? pmssWelcomeFeedItemsRead($feed, 'article', 12) : array()
    );
    $html = pmssWelcomeHomeNewsPartHtmlBuild('Announcements', $news['announcements'], 'https://pulsedmedia.com/clients/announcements.php')
        .pmssWelcomeHomeNewsPartHtmlBuild('Väinämöinen\'s articles', $news['articles'], '', false);
    if ($html === '') return '<p class="h-muted">News is unavailable right now. <a href="https://pulsedmedia.com/clients/announcements.php" target="_blank" rel="noopener">Read the announcements</a>.</p>';
    return $html;
}

/** GET welcome.php?news=1: refresh the cache and answer the list fragment (read-only, same origin). */
function pmssWelcomeHomeNewsEmit($home)
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo pmssWelcomeHomeNewsHtmlRead($home, true);
}

/** @return array{state:string,used:float,total:float,hard:float,percent:float} */
function pmssWelcomeHomeQuotaStateBuild($quotaInfo)
{
    if (!is_array($quotaInfo) || $quotaInfo === array()) return array('state' => 'none', 'used' => 0.0, 'total' => 0.0, 'hard' => 0.0, 'percent' => 0.0);
    $fields = array();
    foreach (array('hardLimit', 'totalSpace', 'usedBytes') as $field) {
        $fields[$field] = isset($quotaInfo[$field]) ? pmssCustomerNonnegativeFloat($quotaInfo[$field]) : null;
    }
    if (in_array(null, $fields, true) || $fields['hardLimit'] == 0 || $fields['totalSpace'] == 0) {
        return array('state' => 'missing', 'used' => 0.0, 'total' => 0.0, 'hard' => 0.0, 'percent' => 0.0);
    }
    $percent = pmssWelcomePercent($fields['usedBytes'], $fields['totalSpace'], 1);
    return array('state' => $percent > 100 ? 'over' : 'ok', 'used' => $fields['usedBytes'], 'total' => $fields['totalSpace'],
        'hard' => $fields['hardLimit'], 'percent' => $percent);
}

/** Traffic over the rolling 30 days, using the same limit and bonus readers as before. */
function pmssWelcomeHomeTrafficStateBuild($trafficData, $trafficIngress, $trafficLimitState, $bandwidthState)
{
    $usedMiB = is_array($trafficData) ? pmssWelcomeTrafficMonthValueRead($trafficData) : null;
    $limit = (int) ($trafficLimitState['limitGiB'] ?? 0);
    $bonus = max(0, (int) ($trafficLimitState['bonusGiB'] ?? 0));
    $state = array('available' => $usedMiB !== null, 'usedGiB' => $usedMiB === null ? 0.0 : $usedMiB / 1024,
        'limitGiB' => $limit > 0 ? $limit + $bonus : 0, 'bonusGiB' => $bonus, 'percent' => 0.0, 'overCap' => false,
        'inboundGiB' => null, 'ratio' => null, 'reduced' => !empty($bandwidthState['isReduced']));
    $inbound = is_array($trafficIngress) ? pmssWelcomeTrafficMonthValueRead($trafficIngress) : null;
    if ($inbound !== null) $state['inboundGiB'] = $inbound / 1024;
    if ($usedMiB !== null && function_exists('pmssTrafficRatioStateBuild')) {
        $ratio = pmssTrafficRatioStateBuild($usedMiB, $inbound);
        if (!empty($ratio['available'])) $state['ratio'] = $ratio;
    }
    if ($state['limitGiB'] > 0 && $usedMiB !== null) {
        $state['percent'] = pmssWelcomePercent($state['usedGiB'], $state['limitGiB'], 1);
        $state['overCap'] = $state['usedGiB'] > $state['limitGiB'];
    }
    return $state;
}

/** One status card: label, value, a bar made of segments, and one meta line. */
function pmssWelcomeHomeMetricHtmlBuild($label, $valueHtml, $ofText, array $segments, $metaHtml, $title = '')
{
    $bar = '';
    foreach ($segments as $segment) {
        $width = max(0, min(100, (float) $segment[1]));
        if ($width > 0) $bar .= '<i class="'.$segment[0].'" style="width:'.round($width, 2).'%"></i>';
    }
    return '<div class="h-metric"'.($title !== '' ? ' title="'.pmssWelcomeHtmlAttr($title).'"' : '').'>'
        .'<div class="h-metric-l">'.$label.'</div>'
        .'<div class="h-metric-v">'.$valueHtml.($ofText !== '' ? '<span>'.$ofText.'</span>' : '').'</div>'
        .'<div class="h-bar'.($bar === '' ? ' h-bar-none' : '').'" role="presentation">'.$bar.'</div>'
        .'<div class="h-metric-m">'.$metaHtml.'</div></div>';
}

/** Bar tone follows the thresholds the usage alerts use (90% disk, 80% traffic), always next to a number. */
function pmssWelcomeHomeTone($percent, $warnAt)
{
    return $percent > 100 ? 't-bad' : ($percent >= $warnAt ? 't-warn' : 's-base');
}

function pmssWelcomeHomeUpgradeLink($billingServiceId, $text)
{
    return '<a href="'.pmssCustomerHtmlAttr(pmssWelcomeTrafficUpgradeUrlBuild($billingServiceId)).'" target="_blank" rel="noopener">'.$text.'</a>';
}

function pmssWelcomeHomeQuotaMetricHtmlBuild(array $quota, $billingServiceId)
{
    if ($quota['state'] === 'none' || $quota['state'] === 'missing') {
        return pmssWelcomeHomeMetricHtmlBuild('Storage', '—', '', array(), $quota['state'] === 'missing' ? 'Quota info is missing' : 'Open from the panel to see usage');
    }
    $meta = number_format($quota['percent'], 1).'% used';
    if ($quota['state'] === 'over') $meta .= ' · burst limit '.pmssFormatBytes($quota['hard'], 2, 0, true);
    if ($quota['percent'] >= 90 && (int) $billingServiceId > 0) $meta .= ' · '.pmssWelcomeHomeUpgradeLink($billingServiceId, 'Add storage');
    return pmssWelcomeHomeMetricHtmlBuild('Storage', pmssFormatBytes($quota['used'], 2, 0, true), 'of '.pmssFormatBytes($quota['total'], 2, 0, true),
        array(array(pmssWelcomeHomeTone($quota['percent'], 90), $quota['percent'])), $meta);
}

function pmssWelcomeHomeTrafficMetricHtmlBuild(array $traffic, $billingServiceId)
{
    if (!$traffic['available']) return pmssWelcomeHomeMetricHtmlBuild('Traffic · 30 days', '—', '', array(), 'Usage data is unavailable right now');
    $details = array();
    if ($traffic['inboundGiB'] !== null) $details[] = 'in '.number_format(round($traffic['inboundGiB'])).' GiB';
    if (is_array($traffic['ratio']) && $traffic['usedGiB'] >= 1) $details[] = 'ratio <span style="color:'.pmssCustomerHtmlAttr($traffic['ratio']['color']).'">'.pmssCustomerHtmlAttr($traffic['ratio']['display']).'</span>';
    $details[] = $traffic['reduced'] ? '<b>speed reduced</b>' : 'full port speed';
    if ($traffic['limitGiB'] <= 0) {
        return pmssWelcomeHomeMetricHtmlBuild('Traffic · 30 days', number_format(round($traffic['usedGiB'])).' GiB', 'out · unlimited', array(),
            implode(' · ', $details));
    }
    $meta = number_format($traffic['percent'], 1).'% · '.implode(' · ', $details);
    if ($traffic['bonusGiB'] > 0) $meta .= ' · bonus '.number_format($traffic['bonusGiB']).' GiB';
    if ($traffic['percent'] >= 80 && (int) $billingServiceId > 0) $meta .= ' · '.pmssWelcomeHomeUpgradeLink($billingServiceId, 'Add traffic');
    return pmssWelcomeHomeMetricHtmlBuild('Traffic · 30 days', number_format(round($traffic['usedGiB'])).' GiB', 'of '.number_format($traffic['limitGiB']).' GiB',
        array(array(pmssWelcomeHomeTone($traffic['percent'], 80), $traffic['percent'])), $meta,
        'Rolling past 30 days of outbound traffic');
}

function pmssWelcomeHomeMemoryMetricHtmlBuild(array $memory)
{
    $limit = isset($memory['limitBytes']) ? $memory['limitBytes'] : null;
    $process = isset($memory['processBytes']) ? $memory['processBytes'] : null;
    $cache = isset($memory['cacheBytes']) ? $memory['cacheBytes'] : null;
    $current = isset($memory['currentBytes']) ? $memory['currentBytes'] : null;
    $shown = $process !== null ? $process : $current;
    if ($shown === null) return pmssWelcomeHomeMetricHtmlBuild('Memory', '—', '', array(), 'RAM usage data is unavailable right now');
    $percent = $limit > 0 ? pmssWelcomePercent($shown, $limit, 1) : 0;
    $segments = $limit > 0 ? array(array(pmssWelcomeHomeTone($percent, 80), $percent)) : array();
    if ($limit > 0 && $cache !== null && $process !== null) $segments[] = array('s-cache', pmssWelcomePercent($cache, $limit, 1));
    $pressure = is_array($memory['pressureStatus'] ?? null) ? (string) $memory['pressureStatus']['status'] : '';
    $meta = ($cache !== null ? 'cache '.pmssFormatBytes($cache, 2, 0, true).' · ' : '').($pressure !== '' ? 'pressure '.strtolower(pmssCustomerHtmlAttr($pressure)) : number_format($percent, 1).'% used');
    return pmssWelcomeHomeMetricHtmlBuild('Memory', pmssFormatBytes($shown, 2, 0, true), $limit > 0 ? 'of '.pmssFormatBytes($limit, 2, 0, true) : '',
        $segments, $meta, 'Process memory; page cache is shown in grey and is reclaimed when needed');
}

/** Server uptime stays (customer request, #820); the host-wide load average is not the account's load and is dropped. */
function pmssWelcomeHomeServerMetricHtmlBuild($uptimePath = '/proc/uptime')
{
    $raw = pmssCustomerTrimmedFileRead($uptimePath);
    $fields = is_string($raw) && $raw !== '' ? preg_split('/\s+/', $raw) : array();
    $uptime = isset($fields[0]) ? pmssWelcomeServerUptimeTextBuild($fields[0]) : null;
    return pmssWelcomeHomeMetricHtmlBuild('Server', is_string($uptime) && $uptime !== '' ? pmssWelcomeHtmlAttr($uptime) : '—', '', array(),
        is_string($uptime) && $uptime !== '' ? 'uptime · shared server' : 'uptime unavailable');
}

/** Alerts appear only when something needs the customer's attention, each with what it means and what to do. */
function pmssWelcomeHomeAlertsHtmlBuild(array $quota, array $traffic, array $memory, $bandwidthState, $billingServiceId)
{
    $alerts = '';
    if ($quota['state'] === 'missing') {
        $alerts .= '<div class="h-alert"><strong>Quota info is missing</strong><p>If this persists for more than an hour, contact support.</p></div>';
    } elseif ($quota['state'] === 'over') {
        $alerts .= '<div class="h-alert h-alert-bad"><strong>Over your storage quota</strong><p>If you go over your burst limit, some applications will not operate and will shut down automatically, and will not restart until you are within quota limit. You are allowed to burst quota up to 7 days.</p></div>';
    }
    if ($traffic['available'] && $traffic['limitGiB'] > 0 && ($traffic['overCap'] || $traffic['reduced'])) {
        $alerts .= '<div class="h-alert'.($traffic['reduced'] ? ' h-alert-bad' : '').'"><strong>'.($traffic['overCap'] ? 'Over your monthly traffic limit' : 'Traffic speed is reduced').'</strong>'
            .pmssWelcomeTrafficDisclosureHtmlBuild($traffic['percent'], $bandwidthState, $billingServiceId, $traffic['overCap'])
            .($traffic['overCap'] ? '<p>'.pmssWelcomeHomeOverCapSpeedText($bandwidthState).'</p>' : '')
            .'<p>'.pmssWelcomeTrafficUpgradeLinkHtmlBuild($billingServiceId).'.</p></div>';
    }
    $limit = isset($memory['limitBytes']) ? $memory['limitBytes'] : null;
    $process = isset($memory['processBytes']) ? $memory['processBytes'] : (isset($memory['currentBytes']) ? $memory['currentBytes'] : null);
    $pressure = is_array($memory['pressureStatus'] ?? null) ? $memory['pressureStatus'] : array();
    if (($pressure['status'] ?? '') === 'THROTTLED' && empty($pressure['oom_events']) && empty($pressure['max_events'])) {
        $alerts .= '<div class="h-alert"><strong>RAM throttle active</strong><p>Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed.</p></div>';
    } elseif ($limit > 0 && $process !== null && pmssWelcomePercent($process, $limit, 1) > 100) {
        $alerts .= '<div class="h-alert h-alert-bad"><strong>RAM limit exceeded</strong><p>Processes may be killed (OOM) until memory usage drops.</p></div>';
    }
    return $alerts;
}

/** The loyalty bonus sits with the other figures; "0%" is shown only when the server records a zero. */
function pmssWelcomeHomeStatusChipsHtmlBuild($bonusDisplayState, $billingServiceId)
{
    $policy = 'https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Free_Bonus_Disk_Policy';
    $state = is_array($bonusDisplayState) ? $bonusDisplayState : array();
    $value = max(0, (int) ($state['value'] ?? 0));
    if (($state['unit'] ?? '') === 'gib') {
        $chip = 'Bonus disk <b>+'.number_format($value).' GiB</b>';
    } elseif (($state['state'] ?? '') === 'applied') {
        $chip = 'Loyalty bonus <b>+'.number_format($value).'%</b>';
    } elseif (($state['state'] ?? '') === 'zero') {
        $chip = 'Loyalty bonus <b>0%</b>';
    } else {
        $chip = 'Loyalty bonus · <b>how it works</b>';
    }
    $note = function_exists('pmssCustomerBonusDisplayNoteBuild') ? pmssCustomerBonusDisplayNoteBuild($state) : '';
    $html = '<a class="h-chip h-chip-bonus" href="'.$policy.'" target="_blank" rel="noopener" title="'.pmssWelcomeHtmlAttr($note !== '' ? $note.' How the bonus works' : 'How the bonus works').'">'.$chip.'</a>';
    if ((int) $billingServiceId > 0) $html .= '<a class="h-chip" href="'.pmssCustomerHtmlAttr(pmssWelcomeTrafficUpgradeUrlBuild($billingServiceId)).'" target="_blank" rel="noopener">Upgrade</a>';
    return $html;
}

/** An account whose main app is OpenClaw: OpenClaw installed and no torrent client switched on. */
function pmssWelcomeHomeAgentFirst(array $appsStatus)
{
    $apps = is_array($appsStatus['apps'] ?? null) ? $appsStatus['apps'] : array();
    if (!isset($apps['openclaw']) || ($apps['openclaw']['state'] ?? 'off') === 'off') return false;
    foreach (array('rtorrent', 'qBittorrent', 'Deluge') as $id) {
        if (isset($apps[$id]) && ($apps[$id]['state'] ?? 'off') !== 'off') return false;
    }
    return true;
}

/** Visible apps in display order, with name, description, state word and where Open goes. */
/** The common apps that fill the first three rows when the account does not run them yet, in this order. */
function pmssWelcomeHomeCommonApps()
{
    return array('rtorrent', 'qBittorrent', 'Deluge', 'jellyfin', 'sonarr', 'radarr', 'prowlarr', 'rclone', 'openclaw');
}

/**
 * Every app tile for Welcome, in order: the apps the account runs (a featured OpenClaw first), then the common apps it
 * does not run, until nine plain tiles fill three rows; everything after that is in "More apps" ('more' => true).
 * action: open (ready with a URL), start (one click: off or stopped), setup (a media app not installed: the
 * Configure tab installs the media stack), manage (needs a step in Configure, e.g. a login), none (setting up).
 * restart: a running app can be restarted from its tile.
 */
function pmssWelcomeHomeAppsBuild(array $appsStatus, $agentFirst)
{
    $apps = is_array($appsStatus['apps'] ?? null) ? $appsStatus['apps'] : array();
    $names = array('jellyfin' => 'Jellyfin', 'sonarr' => 'Sonarr', 'radarr' => 'Radarr', 'prowlarr' => 'Prowlarr',
        'sabnzbd' => 'SABnzbd', 'autobrr' => 'Autobrr', 'cloudplow' => 'Cloudplow', 'rtorrent' => 'ruTorrent',
        'qBittorrent' => 'qBittorrent', 'Deluge' => 'Deluge', 'rclone' => 'rclone', 'openclaw' => 'OpenClaw');
    $toggle = is_file(__DIR__.'/toggleAppCatalog.php') ? (require __DIR__.'/toggleAppCatalog.php') : array();
    foreach ($toggle as $id => $row) $names[$id] = $row['label'];
    $descriptions = function_exists('pmssCustomerAppsDescriptionsRead') ? pmssCustomerAppsDescriptionsRead() : array();
    $descriptions['ruTorrent'] = $descriptions['rTorrent + ruTorrent'] ?? 'Your main torrent client';
    $ideas = array('qBittorrent' => 'A second torrent client with a modern web UI', 'Deluge' => 'A torrent client with a web UI',
        'jellyfin' => 'Stream your library to TV, phone and browser', 'sonarr' => 'Finds and downloads TV episodes',
        'radarr' => 'Finds and downloads movies', 'prowlarr' => 'One indexer list for Sonarr and Radarr',
        'rclone' => 'Sync files to and from cloud storage', 'openclaw' => 'An AI agent that works in your account, on your AI credits');
    $media = array('jellyfin', 'sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'autobrr', 'cloudplow');
    $tile = function ($id, $app) use ($names, $descriptions, $ideas, $agentFirst) {
        $name = $names[$id] ?? (string) $id;
        $state = $app === null ? 'absent' : (string) ($app['state'] ?? '');
        $url = $app === null ? '' : (string) ($app['url'] ?? '');
        $ready = $state === 'running' || ($id === 'rtorrent' && $state === 'not-running');
        if ($state === 'absent') $action = 'setup';
        elseif ($ready && $url !== '') $action = 'open';
        elseif (in_array($state, array('off', 'stopped', 'not-running', 'failed', 'gave up'), true)) $action = 'start';
        elseif ($state === 'installing') $action = 'none';
        else $action = 'manage';
        $desc = (string) ($descriptions[$name] ?? '');
        return array('id' => (string) $id, 'name' => $name, 'desc' => $desc !== '' ? $desc : ($ideas[$id] ?? ''), 'state' => $state, 'url' => $url,
            'open' => $action === 'open', 'action' => $action, 'restart' => $state === 'running',
            'hero' => $agentFirst && $id === 'openclaw' && $state !== 'off' && $state !== 'absent', 'more' => false);
    };
    $active = $inactive = array();
    foreach ($apps as $id => $app) {
        if ($id === 'lighttpd') continue;
        $t = $tile($id, is_array($app) ? $app : array());
        if ($t['state'] === 'off') $inactive[$t['id']] = $t; else $active[$t['id']] = $t;
    }
    // A media app the account has never installed is not in the status list; the common ones still get a tile.
    foreach (pmssWelcomeHomeCommonApps() as $id) {
        if (!isset($active[$id]) && !isset($inactive[$id]) && in_array($id, $media, true)) $inactive[$id] = $tile($id, null);
    }
    $rank = array_flip(pmssWelcomeHomeCommonApps());
    $order = function ($a, $b) use ($rank) {
        if ($a['hero'] !== $b['hero']) return $b['hero'] - $a['hero'];
        return ($rank[$a['id']] ?? 99) - ($rank[$b['id']] ?? 99) ?: strcmp($a['name'], $b['name']);
    };
    uasort($active, $order);
    uasort($inactive, $order);
    $list = array_values($active);
    $plain = count(array_filter($list, function ($t) { return !$t['hero']; }));
    foreach ($inactive as $t) {
        // An OpenClaw-first account is not nudged back to a second torrent client in the first rows.
        $nudge = isset($rank[$t['id']]) && !($agentFirst && in_array($t['id'], array('qBittorrent', 'Deluge'), true));
        $t['more'] = !($nudge && $plain < 9);
        if (!$t['more']) $plain++;
        $list[] = $t;
    }
    // PHP 7 sorts are not stable, so the order is kept by splitting rather than sorting.
    return array_merge(array_values(array_filter($list, function ($t) { return !$t['more']; })), array_values(array_filter($list, function ($t) { return $t['more']; })));
}

/** One app tile: state, a one-click action where the panel has one, Restart while it runs. */
function pmssWelcomeHomeAppTileHtml(array $app)
{
    $label = pmssWelcomeHtmlAttr($app['name']);
    $sr = '<span class="h-sr"> '.$label.'</span>';
    $configure = 'href="configure.php#apps" onclick="return pmssWelcomeTabOpen(\'#pmssconfigure\', this)"';
    if ($app['action'] === 'open') $button = '<a class="h-btn" href="'.pmssWelcomeHtmlAttr($app['url']).'" target="_blank" rel="noopener">Open'.$sr.'</a>';
    elseif ($app['action'] === 'start') $button = '<button type="button" class="h-btn" data-tile-action="start">Start'.$sr.'</button>';
    elseif ($app['action'] === 'setup') $button = '<a class="h-btn h-btn-quiet" '.$configure.'>Set up'.$sr.'</a>';
    elseif ($app['action'] === 'manage') $button = '<a class="h-btn h-btn-quiet" '.$configure.'>Manage'.$sr.'</a>';
    else $button = '';
    if (!empty($app['restart'])) $button .= '<button type="button" class="h-btn h-btn-quiet" data-tile-action="restart">Restart'.$sr.'</button>';
    if ($app['state'] === 'absent') list($word, $class) = array('Not set up', '');
    elseif ($app['state'] === 'off') list($word, $class) = array('Off', '');
    else list($word, $class) = pmssWelcomeHomeStateWord($app['state']);
    $classes = 'h-app'.($app['hero'] ? ' h-app-hero' : '').(in_array($app['state'], array('off', 'absent'), true) ? ' h-app-ghost' : '').(!empty($app['more']) ? ' h-app-more' : '');
    return '<div class="'.$classes.'" data-app="'.pmssWelcomeHtmlAttr($app['id']).'">'
        .'<div class="h-app-top">'.pmssWelcomeHomeAppIcon($app['id']).'<span class="h-app-n">'.$label.'</span>'
        .'<span class="h-state '.$class.'" data-state-word>'.$word.'</span></div>'
        .($app['desc'] !== '' ? '<div class="h-app-d">'.pmssWelcomeHtmlAttr($app['desc']).'</div>' : '')
        .'<div class="h-app-acts">'.$button.'</div><div class="h-app-note" role="status"></div></div>';
}

function pmssWelcomeHomeStateWord($state)
{
    $words = array('running' => array('Running', 'h-state-run'), 'installing' => array('Setting up', 'h-state-warn'),
        'not-running' => array('Not running', 'h-state-warn'), 'stopped' => array('Stopped', 'h-state-warn'),
        'not secured' => array('Needs a login', 'h-state-warn'), 'gave up' => array('Gave up', 'h-state-bad'),
        'failed' => array('Failed', 'h-state-bad'));
    return $words[$state] ?? array('Status unavailable', '');
}

function pmssWelcomeHomeAppIcon($id)
{
    $media = array('jellyfin', 'sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'autobrr', 'Scryer');
    $paths = array(
        'torrent' => '<path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/>',
        'media' => '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M10 9l4 2-4 2z"/><path d="M8 20h8"/>',
        'cloud' => '<path d="M7 18h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6 9.5 4.25 4.25 0 0 0 7 18z"/>',
        'agent' => '<rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01"/>',
        'files' => '<path d="M4 6h6l2 2h8v10H4z"/>',
    );
    $kind = in_array($id, array('rtorrent', 'qBittorrent', 'Deluge', 'qui'), true) ? 'torrent'
        : (in_array($id, $media, true) ? 'media' : (in_array($id, array('rclone', 'cloudplow'), true) ? 'cloud'
        : ($id === 'openclaw' ? 'agent' : 'files')));
    return '<svg class="h-ico" viewBox="0 0 24 24" aria-hidden="true">'.$paths[$kind].'</svg>';
}

function pmssWelcomeHomeAppsHtmlBuild(array $apps)
{
    $more = count(array_filter($apps, function ($app) { return !empty($app['more']); }));
    $html = '<div class="h-apps" id="h-apps">';
    foreach ($apps as $app) $html .= pmssWelcomeHomeAppTileHtml($app);
    $html .= '</div><div class="h-apps-foot">';
    if ($more > 0) $html .= '<button type="button" class="h-btn h-btn-quiet" id="h-apps-more" aria-expanded="false" onclick="pmssAppsMoreToggle(this)">More apps ('.$more.')</button>';
    return $html.'<a class="h-head-link" href="configure.php#apps" onclick="return pmssWelcomeTabOpen(\'#pmssconfigure\', this)">All apps and settings in Configure</a></div>';
}
/** Connection details with copy buttons; client download links only to sites checked live. */
function pmssWelcomeHomeConnectHtmlBuild($username, array $appsStatus, $agentFirst)
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_match('/^[a-z0-9.-]{1,253}$/', $host) ? $host : strtolower((string) (function_exists('gethostname') ? gethostname() : ''));
    $rows = array(array('Host', $host), array('Username', (string) $username), array('SFTP / SSH port', '22'));
    $html = '<section class="h-card" aria-labelledby="h-connect-title"><div class="h-head"><h2 id="h-connect-title" class="h-label">Connect</h2>'
        .'<a class="h-head-link" href="data/">Browse files over HTTP</a></div><dl class="h-kv">';
    foreach ($rows as $row) {
        $html .= '<dt>'.$row[0].'</dt><dd>'.pmssWelcomeHtmlAttr($row[1]).'</dd><button type="button" class="h-copy" data-copy="'.pmssWelcomeHtmlAttr($row[1]).'" aria-label="Copy '.strtolower($row[0]).'">Copy</button>';
    }
    $html .= '</dl>';
    $apps = is_array($appsStatus['apps'] ?? null) ? $appsStatus['apps'] : array();
    if (!$agentFirst && isset($apps['rtorrent']) && ($apps['rtorrent']['state'] ?? 'off') !== 'off') {
        $html .= '<p class="h-foot">Drop .torrent files into the <code>watch</code> folder and rTorrent starts them automatically.</p>';
    }
    $data = dirname(__DIR__).'/data';
    $entries = is_dir($data) ? @scandir($data) : false;
    if (is_array($entries) && count($entries) <= 2 && !$agentFirst) {
        $html .= '<p class="h-foot">New here? The <a href="https://wiki.pulsedmedia.com/index.php/Getting_Started_with_the_Seedbox_Dashboard" target="_blank" rel="noopener">getting started guide</a> walks through the first steps.</p>';
    }
    return $html.'<p class="h-foot">Guides: <a href="https://wiki.pulsedmedia.com/index.php/Connecting_via_SFTP_using_filezilla" target="_blank" rel="noopener">SFTP with FileZilla</a> · '
        .'<a href="https://wiki.pulsedmedia.com/index.php/How_to_connect_via_SSH" target="_blank" rel="noopener">SSH</a> · Clients: '
        .'<a href="https://filezilla-project.org/download.php?type=client" target="_blank" rel="noopener">FileZilla</a> · '
        .'<a href="https://winscp.net/eng/download.php" target="_blank" rel="noopener">WinSCP</a></p></section>';
}

/** The outbound speed rule while over the traffic limit, worded as the old over-limit warning worded it. */
function pmssWelcomeHomeOverCapSpeedText($bandwidthState)
{
    $capMbit = is_numeric($bandwidthState['effectiveCapMbit'] ?? null) ? max(0, (int) $bandwidthState['effectiveCapMbit']) : 0;
    return (!empty($bandwidthState['isReduced'])
            ? 'Datacenter external outbound (TO internet) bandwidth is limited to '.number_format($capMbit).' Mbps.'
            : 'While over the cap, datacenter external outbound (TO internet) bandwidth can be limited to '.number_format($capMbit).' Mbps.')
        .' Datacenter internal and inbound bandwidth is unrestricted.';
}/* AI credits (operator 2026-10-22: "the dashboard should show Virsilipas tokens and credits if key is there"; the ask bar
 * shows only with credits, with roughly how many questions they buy, a customer suggestion the operator endorsed).
 * The account's own key (~/.virsilipasKey, ADR 0090) is sent only to the PM credit service named in it; the page
 * gets numbers back, never the key. OpenClaw-profile accounts have no Pikkumies, so this does not depend on it. */


/** The account's key and endpoint, under the same gate the account agent uses (no links, own file, mode 600), or null. */
function pmssWelcomeHomeCreditsKeyRead($home)
{
    // The PM credit service; this file runs top to bottom, so a top-level const here would not exist yet when the page calls this.
    $ourEndpoint = 'https://virsilipas.mcx.fi/v1';
    $file = rtrim((string) $home, '/').'/.virsilipasKey';
    clearstatcache(true, $file);
    if (!is_file($file) || is_link($file) || (function_exists('posix_geteuid') && fileowner($file) !== posix_geteuid())
        || (fileperms($file) & 0077) !== 0 || filesize($file) > 4096) return null;
    $settings = @parse_ini_string((string) @file_get_contents($file), false, INI_SCANNER_RAW);
    $key = is_array($settings) && is_string($settings['key'] ?? null) ? trim($settings['key']) : '';
    $endpoint = is_array($settings) && is_string($settings['endpoint'] ?? null) ? rtrim(trim($settings['endpoint']), '/') : $ourEndpoint;
    if (preg_match('/^vsk-[A-Za-z0-9_-]{16,128}$/D', $key) !== 1 || $endpoint !== $ourEndpoint) return null;
    return array('key' => $key, 'endpoint' => $endpoint);
}

/** The figures from GET /usage: balance, tokens used in 30 days, share left of the newest grant. null on any failure. */
function pmssWelcomeHomeCreditsFetch(array $credentials)
{
    $context = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 4, 'ignore_errors' => true,
        'header' => "Authorization: Bearer ".$credentials['key']."\r\nAccept: application/json\r\n")));
    $raw = @file_get_contents($credentials['endpoint'].'/usage?days=30&grants=10', false, $context, 0, 1048576);
    $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) ? (int) $m[1] : 0;
    $data = is_string($raw) && $status === 200 ? json_decode($raw, true) : null;
    if (!is_array($data) || !is_numeric($data['balance'] ?? null)) return null;
    $tokens = 0;
    foreach (is_array($data['daily'] ?? null) ? $data['daily'] : array() as $day) {
        if (is_array($day)) $tokens += (int) ($day['prompt_tokens'] ?? 0) + (int) ($day['completion_tokens'] ?? 0);
    }
    $grant = 0;
    foreach (is_array($data['grants'] ?? null) ? $data['grants'] : array() as $row) {
        $amount = is_array($row) ? (int) ($row['credits'] ?? 0) : 0;
        if ($amount > 0) { $grant = $amount; break; }
    }
    $balance = (int) $data['balance'];
    return array('balance' => $balance, 'tokens_30d' => $tokens,
        'pct_left' => $grant > 0 ? max(0.0, min(100.0, $balance / $grant * 100)) : null);
}

/** @return array{state:string,balance:int,pct_left:?float,tokens_30d:?int,questions:?int,text:string,value:string,meta:string}
 *  state: none (no usable key), unknown (could not read), ok. Cached per account for 60 s, a stale copy kept for an hour. */
function pmssWelcomeHomeCreditsRead($home)
{
    $result = array('state' => 'unknown', 'balance' => 0, 'pct_left' => null, 'tokens_30d' => null, 'questions' => null, 'text' => '', 'value' => '', 'meta' => '');
    $credentials = pmssWelcomeHomeCreditsKeyRead($home);
    if ($credentials === null) { $result['state'] = 'none'; return $result; }
    $dir = rtrim((string) $home, '/').'/.cache/pmss-welcome';
    $cache = $dir.'/credits.json';
    $cached = is_file($cache) && !is_link($cache) ? json_decode((string) @file_get_contents($cache, false, null, 0, 65536), true) : null;
    $age = is_array($cached) ? time() - (int) ($cached['at'] ?? 0) : PHP_INT_MAX;
    $keyTag = substr(hash('sha256', $credentials['key']), 0, 12);
    $figures = is_array($cached) && ($cached['key'] ?? '') === $keyTag && $age >= 0 && $age < 60 ? $cached['figures'] : null;
    if ($figures === null) {
        $figures = pmssWelcomeHomeCreditsFetch($credentials);
        if ($figures !== null && (is_dir($dir) || @mkdir($dir, 0700, true)) && !is_link($dir)) {
            $tmp = @tempnam($dir, 'credits.');
            if ($tmp !== false && @file_put_contents($tmp, json_encode(array('at' => time(), 'key' => $keyTag, 'figures' => $figures))) !== false) {
                @chmod($tmp, 0600);
                if (!@rename($tmp, $cache)) @unlink($tmp);
            } elseif ($tmp !== false) {
                @unlink($tmp);
            }
        } elseif ($figures === null && is_array($cached) && ($cached['key'] ?? '') === $keyTag && $age < 3600) {
            $figures = $cached['figures'];
        }
    }
    if (!is_array($figures)) return $result;
    $balance = (int) $figures['balance'];
    $result['state'] = 'ok';
    $result['balance'] = $balance;
    $result['pct_left'] = $figures['pct_left'];
    $result['tokens_30d'] = (int) $figures['tokens_30d'];
    $result['value'] = $balance > 0 ? pmssWelcomeHomeCreditsCompact($balance) : '0';
    $used = $result['tokens_30d'] > 0 ? pmssWelcomeHomeCreditsCompact($result['tokens_30d']).' tokens used in 30 days' : 'no tokens used in 30 days';
    if ($balance <= 0) { $result['meta'] = $used.' · more are added with each paid invoice'; return $result; }
    // Average credits per Pikkumies question on the default model (wiki "Pikkumies on PMSS", What it costs).
    $questions = intdiv($balance, 18000);
    $result['questions'] = $questions;
    $result['text'] = $questions >= 1
        ? 'About '.number_format($questions).' Pikkumies question'.($questions === 1 ? '' : 's').' left on the default model'
        : 'Less than one typical question left';
    $result['meta'] = ($questions >= 1 ? '≈'.number_format($questions).' question'.($questions === 1 ? '' : 's') : 'under one question').' · '.$used;
    return $result;
}

/** 4,960,000 -> 4.9M, 35,400 -> 35.4k; always rounded down so it never shows more than is there. */
function pmssWelcomeHomeCreditsCompact($credits)
{
    if ($credits >= 1000000) return rtrim(rtrim(number_format(floor($credits / 100000) / 10, 1, '.', ''), '0'), '.').'M';
    if ($credits >= 1000) return rtrim(rtrim(number_format(floor($credits / 100) / 10, 1, '.', ''), '0'), '.').'k';
    return (string) (int) $credits;
}

/** The status-strip card; the page fills it from ?credits=1 so a slow read never holds the page. */
function pmssWelcomeHomeCreditsMetricHtmlBuild()
{
    return '<div class="h-metric" id="h-credits" title="AI credits on this account\'s key: what Pikkumies and OpenClaw spend. Unused credits stay on the account.">'
        .'<div class="h-metric-l">AI credits</div>'
        .'<div class="h-metric-v" data-credits-value>…</div>'
        .'<div class="h-bar h-bar-none" role="presentation" data-credits-bar></div>'
        .'<div class="h-metric-m" data-credits-meta>reading the balance</div></div>';
}

/** GET welcome.php?credits=1: the account's own credit figures (numbers only, never the key). */
function pmssWelcomeHomeCreditsEmit($home)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(pmssWelcomeHomeCreditsRead($home));
}
/* Get help (operator 2026-10-22: "build a open support ticket form, which is, it looks like normal ... just get this
 * released"). Step one: a plain ticket through the AI-key ticket route (pulsedmedia.com/remote/ticket.php); it spends
 * no AI credits. Without a usable key, and for billing, the client-area form. Pikkumies-first comes in step two. */

/** The topics on the form; billing goes to the client area, never through the key route (it carries no account authority). */
function pmssWelcomeHomeSupportTopics()
{
    return array('working' => 'Not working', 'slow' => 'Slow', 'down' => 'Server down', 'billing' => 'Billing / account');
}

/** Common password and key forms removed before anything leaves the account. */
function pmssWelcomeHomeSupportRedact($text)
{
    $text = preg_replace('/\b(vsk|sk|pk|ghp|gho|xox[abpr])[-_][A-Za-z0-9_-]{12,}/', '[removed key]', (string) $text);
    // After ":" or "=" any value of 4+ characters goes; after "is" only one with a digit, so "password is not working" stays.
    $text = preg_replace('/\b(pass(word|wd)?|pwd|secret|token|api[-_ ]?key)(\s*[:=]\s*)\S{4,}/i', '$1$3[removed]', $text);
    return preg_replace('/\b(pass(word|wd)?|pwd|secret|token|api[-_ ]?key)(\s+is\s+)(?=\S*[0-9])\S+/i', '$1$3[removed]', $text);
}

/** The ticket request: subject from the topic and the first line, message with where it came from. */
function pmssWelcomeHomeSupportRequestBuild($topic, $text, $email)
{
    $topics = pmssWelcomeHomeSupportTopics();
    $label = isset($topics[$topic]) ? $topics[$topic] : 'Other';
    $clean = trim(pmssWelcomeHomeSupportRedact(str_replace(array("\r\n", "\r"), "\n", (string) $text)));
    $first = trim((string) strtok($clean, "\n"));
    if (function_exists('mb_strimwidth')) $first = mb_strimwidth($first, 0, 80, '…', 'UTF-8');
    else $first = strlen($first) > 80 ? substr($first, 0, 79).'…' : $first;
    return array('email' => trim((string) $email), 'subject' => $label.': '.$first,
        'message' => $clean."\n\n(Sent from the panel's Get help form, topic: ".$label.')', 'priority' => 'Medium');
}

/** What the customer is told for each answer of the ticket route. */
function pmssWelcomeHomeSupportAnswer($code, $body)
{
    $data = is_string($body) ? json_decode($body, true) : null;
    $ticket = is_array($data) && isset($data['ticket']) && preg_match('/^[A-Za-z0-9-]{1,32}$/D', (string) $data['ticket']) ? (string) $data['ticket'] : '';
    if ($code === 201) return array('ok' => true, 'ticket' => $ticket, 'message' => $ticket !== '' ? 'Ticket #'.$ticket.' opened. You can follow it in the client area.' : 'Your ticket was opened. You can follow it in the client area.');
    $map = array(
        401 => 'The email or the account key was not accepted. Use the email of your Pulsed Media account.',
        429 => 'Too many tickets were opened just now. Try again later, or use the client area.',
        413 => 'The message is too long. Shorten it and send again.',
        400 => 'The ticket could not be opened as written. Check the text and the email.',
    );
    return array('ok' => false, 'ticket' => '', 'message' => isset($map[$code]) ? $map[$code] : 'Support could not be reached just now. Use the client area or email support@pulsedmedia.com.');
}

/** POST welcome.php?support=1 (XHR only, ADR 0133): opens the ticket, answers JSON. Never echoes the key. */
function pmssWelcomeHomeSupportEmit($home)
{
    $mode = is_string($_GET['support'] ?? null) ? $_GET['support'] : '1';
    if ($mode === 'activity') { pmssWelcomeHomeSupportActivityEmit($home); return; }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (function_exists('pmssFrontendPostActionRequired')) pmssFrontendPostActionRequired();
    elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== 0) { http_response_code(403); exit; }
    $topic = isset($_POST['topic']) && is_string($_POST['topic']) ? $_POST['topic'] : '';
    $text = isset($_POST['text']) && is_string($_POST['text']) ? $_POST['text'] : '';
    $email = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
    $credentials = pmssWelcomeHomeCreditsKeyRead($home);
    if ($topic === 'billing' || $credentials === null) {
        echo json_encode(array('ok' => false, 'ticket' => '', 'clientArea' => true, 'message' => 'Open this one in the client area.'));
        return;
    }
    if (trim($text) === '' || (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > 8000 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
        echo json_encode(array('ok' => false, 'ticket' => '', 'message' => 'Write what is wrong (up to 8000 characters) and a valid email.'));
        return;
    }
    if ($mode === 'pikkumies') {
        $nonce = isset($_POST['nonce']) && is_string($_POST['nonce']) ? $_POST['nonce'] : '';
        $answer = pmssWelcomeHomeSupportPikkumiesSend($home, $topic, $text, $nonce, $email);
        pmssWelcomeHomeSupportLog($home, array('event' => 'sent', 'route' => 'pikkumies', 'topic' => $topic,
            'outcome' => !empty($answer['ok']) ? 'accepted' : 'refused', 'message' => (string) ($answer['message'] ?? ''), 'chars' => strlen($text)));
        echo json_encode($answer);
        return;
    }
    $body = json_encode(pmssWelcomeHomeSupportRequestBuild($topic, $text, $email), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $context = stream_context_create(array('http' => array('method' => 'POST', 'timeout' => 30, 'ignore_errors' => true, 'content' => $body,
        'header' => "Authorization: Bearer ".$credentials['key']."\r\nContent-Type: application/json\r\nAccept: application/json\r\n")));
    $raw = @file_get_contents('https://pulsedmedia.com/remote/ticket.php', false, $context, 0, 65536);
    $code = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) ? (int) $m[1] : 0;
    $answer = pmssWelcomeHomeSupportAnswer($code, $raw);
    pmssWelcomeHomeSupportLog($home, array('event' => 'sent', 'route' => 'ticket', 'topic' => $topic, 'outcome' => !empty($answer['ok']) ? 'opened' : 'failed',
        'http' => $code, 'ticket' => (string) ($answer['ticket'] ?? ''), 'chars' => strlen($text)));
    echo json_encode($answer);
}

/**
 * One row in the account's Get help log, ~/.pmss/support-log.jsonl (operator 2026-10-23: "deterministic log additions"):
 * when, which route, what happened and Pikkumies' Result line; never the question text. The newest 500 rows are kept.
 */
function pmssWelcomeHomeSupportLog($home, array $row)
{
    $dir = rtrim((string) $home, '/').'/.pmss';
    if ((!is_dir($dir) && !@mkdir($dir, 0700)) || is_link($dir)) return;
    $file = $dir.'/support-log.jsonl';
    if (is_link($file)) return;
    @file_put_contents($file, json_encode(array('ts' => gmdate('c')) + $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    if ((int) @filesize($file) > 262144) {
        $rows = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($rows) && count($rows) > 500) @file_put_contents($file, implode("\n", array_slice($rows, -500))."\n", LOCK_EX);
    }
}

/** The said_at of the newest "answered" row in the log, so each Pikkumies answer is logged once. */
function pmssWelcomeHomeSupportLogLastAnswer($home)
{
    $file = rtrim((string) $home, '/').'/.pmss/support-log.jsonl';
    if (!is_file($file) || is_link($file)) return 0;
    $size = (int) @filesize($file);
    $tail = (string) @file_get_contents($file, false, null, max(0, $size - 16384));
    return preg_match_all('/"event":"answered"[^\n]*"said_at":(\d+)/', $tail, $m) ? (int) max($m[1]) : 0;
}

/** The account's notification email, to prefill the form (a regular own file only). */
function pmssWelcomeHomeSupportEmailDefault($home)
{
    $file = rtrim((string) $home, '/').'/.notifyEmail';
    if (!is_file($file) || is_link($file) || filesize($file) > 512) return '';
    $email = trim((string) @file_get_contents($file));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/* Get help, step two: where Pikkumies runs, "Not working" and "Slow" go to Pikkumies first, on the customer's own
 * AI credits; Server down and Billing / account never do. Pikkumies opens the ticket itself when it cannot fix it. */

/** The question handed to Pikkumies: the customer's text inside a fixed frame, at most 4000 characters in all. */
function pmssWelcomeHomeSupportPikkumiesPrompt($topic, $text, $email = '')
{
    $topics = pmssWelcomeHomeSupportTopics();
    $head = 'Support request from the panel\'s Get help form (topic: '.(isset($topics[$topic]) ? $topics[$topic] : 'Other').").\n\n";
    $tail = "\n\nPlease check this on my seedbox. Write for me, not for a technician: plain words, with no commands, file"
        ." paths, code or logs, also in anything you write while you work.\n"
        ."Start your answer with exactly one of these lines, keeping the word Result: in English:\n"
        ."Result: All fine, no ticket needed.\n"
        ."Result: Fixed, no ticket needed: <what you changed, in a few words>.\n"
        ."Result: Not fixed, I opened ticket #<number>.\n"
        ."Result: Not fixed, ticket not sent: <why>.\n"
        ."Then at most five short lines on what you checked and what you found.\n"
        .'If everything is fine, or you fixed it, do not open a ticket. If you cannot fix it, or the cause is on Pulsed'
        .' Media\'s side (server, network, hardware), open a support ticket with your findings; I agree to that ticket being'
        .' sent. Do not attach logs or this conversation unless I say so.'
        .(filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) ? ' My email for the ticket: '.trim((string) $email).'.' : '');
    $clean = trim(pmssWelcomeHomeSupportRedact(str_replace(array("\r\n", "\r"), "\n", (string) $text)));
    $room = 4000 - strlen($head) - strlen($tail);
    if (function_exists('mb_substr')) $clean = mb_substr($clean, 0, $room, 'UTF-8');
    else $clean = substr($clean, 0, $room);
    return $head.$clean.$tail;
}

/**
 * The account's Pikkumies command, only when the installed build has the command's own code file (e.g. EternalAsk.php).
 * Pikkumies treats words it does not know as a question to the AI, which spends the customer's credits: a build
 * without `eternal activity` answered that "command" with a 4-minute AI run (QA VM, 2026-10-22). Never rely on the
 * word being recognised; require its code file.
 */
function pmssWelcomeHomeSupportPikkumiesBinary($home, $lib)
{
    $root = rtrim((string) $home, '/').'/.mcxPikkumies/pikkumies';
    $binary = $root.'/bin/pikkumies';
    return is_file($binary) && is_executable($binary) && preg_match('/^[A-Za-z]+\.php$/D', (string) $lib) && is_file($root.'/lib/'.$lib) ? $binary : '';
}

/** Runs the Pikkumies command as the account with $stdin; returns array(exit code, first output line). */
function pmssWelcomeHomeSupportPikkumiesRun($binary, array $args, $stdin, $seconds)
{
    $command = 'timeout '.(int) $seconds.' '.escapeshellarg($binary).' '.implode(' ', array_map('escapeshellarg', $args));
    $process = @proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) return array(-1, '');
    fwrite($pipes[0], (string) $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1], 65536);
    $err = (string) stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return array($code, trim($out !== '' ? $out : $err));
}

/** What the customer reads for each `pikkumies ask` exit code (mcxPikkumies ADR 0075); ok only for 0. */
function pmssWelcomeHomeSupportPikkumiesAnswer($code)
{
    if ($code === 0) return array('ok' => true, 'pikkumies' => true, 'message' => 'Pikkumies is checking it now. If all is fine it tells you no ticket is needed; if it cannot fix it, it opens the ticket.');
    $map = array(
        2 => 'Pikkumies needs its one-time setup first. Open the Pikkumies tab, or send this to support now.',
        3 => 'Pikkumies has unsent text in its window. Send or clear it there, or send this to support now.',
        4 => 'Pikkumies took several questions just now. Try again in a minute, or send this to support now.',
        5 => 'Pikkumies is starting. Try again shortly, or send this to support now.',
        6 => 'Pikkumies is busy with another question. Try again shortly, or send this to support now.',
    );
    return array('ok' => false, 'pikkumies' => true, 'message' => isset($map[$code]) ? $map[$code] : 'Pikkumies could not take it just now. Send this to support now instead.');
}

/** POST welcome.php?support=pikkumies: hands the form to Pikkumies (already behind the POST/XHR guard). */
function pmssWelcomeHomeSupportPikkumiesSend($home, $topic, $text, $nonce, $email = '')
{
    if ($topic !== 'working' && $topic !== 'slow') return array('ok' => false, 'pikkumies' => true, 'message' => 'This topic goes straight to support.');
    list($dir, $name) = function_exists('pmssPikkumiesPanelAccount') ? pmssPikkumiesPanelAccount() : array('', '');
    $state = pmssWelcomePikkumiesStateRead($home, $name);
    $binary = pmssWelcomeHomeSupportPikkumiesBinary($home, 'EternalAsk.php');
    if (empty($state['visible']) || $binary === '') return array('ok' => false, 'pikkumies' => true, 'message' => 'Pikkumies is not available on this account. Send this to support now.');
    // The credit service refuses a request it cannot cover up front, so a check stops early well before the balance is
    // 0: QA support checks used about 10,000 to 60,000 credits each, and one was refused with 39,504 left (2026-10-22).
    // The reservation covers the call's prompt, which grows with Pikkumies' session: a check was refused with 44,602
    // left (2026-10-23). Hand it over only with the top of that range left.
    $credits = pmssWelcomeHomeCreditsRead($home);
    if ($credits['state'] === 'ok' && (float) $credits['balance'] < 60000) return array('ok' => false, 'pikkumies' => true, 'message' => 'Your AI credits are too low for Pikkumies to look into this. Send this to support now.');
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/D', (string) $nonce)) return array('ok' => false, 'pikkumies' => true, 'message' => 'Reload the page and try again.');
    list($code) = pmssWelcomeHomeSupportPikkumiesRun($binary, array('ask', '--nonce='.$nonce), pmssWelcomeHomeSupportPikkumiesPrompt($topic, $text, $email), 20);
    return pmssWelcomeHomeSupportPikkumiesAnswer($code) + array('sent' => time());
}

/**
 * GET welcome.php?support=activity: Pikkumies' state for the form while it works. at = its newest activity of any kind
 * (to tell started and finished); said / said_at = its newest own words, never a tool call line, so no command, path or
 * argument reaches the page (operator 2026-10-23: "with shell commands shown"). A build without said shows none.
 */
function pmssWelcomeHomeSupportActivityEmit($home)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $answer = array('state' => '', 'at' => 0, 'said' => '', 'said_at' => 0);
    // Only where Pikkumies windows run: elsewhere `eternal ...` is a question to the AI, not a command.
    $entry = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
    $state = pmssWelcomePikkumiesStateRead($home, is_array($entry) ? (string) ($entry['name'] ?? '') : '');
    $binary = empty($state['visible']) ? '' : pmssWelcomeHomeSupportPikkumiesBinary($home, 'EternalActivity.php');
    if ($binary !== '') {
        list($code, $out) = pmssWelcomeHomeSupportPikkumiesRun($binary, array('eternal', 'activity', '--json'), '', 8);
        $data = $code === 0 ? json_decode($out, true) : null;
        if (is_array($data)) {
            $answer['state'] = preg_match('/^[a-z]{1,16}$/D', (string) ($data['state'] ?? '')) ? (string) $data['state'] : '';
            $answer['at'] = isset($data['at']) && is_int($data['at']) && $data['at'] > 0 ? $data['at'] : 0;
            $said = trim((string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', ' ', (string) ($data['said'] ?? '')));
            // An answer is shown and logged as its Result sentence only; the rest of it is in the Pikkumies tab.
            if (preg_match('/^Result:\s.*?[.!?](?=\s|$)/u', $said, $result)) $said = $result[0];
            $answer['said'] = function_exists('mb_strimwidth') ? mb_strimwidth(trim($said), 0, 200, '…', 'UTF-8') : substr(trim($said), 0, 200);
            $answer['said_at'] = $answer['said'] !== '' && isset($data['said_at']) && is_int($data['said_at']) && $data['said_at'] > 0 ? $data['said_at'] : 0;
            if (strncmp($answer['said'], 'Result:', 7) === 0 && $answer['said_at'] > pmssWelcomeHomeSupportLogLastAnswer($home)) {
                pmssWelcomeHomeSupportLog($home, array('event' => 'answered', 'route' => 'pikkumies', 'result' => $answer['said'], 'said_at' => $answer['said_at']));
            }
        }
    }
    echo json_encode($answer);
}


?>
