<?php
/** Customer app controls and account settings. */
require_once __DIR__.'/scriptsInc.php';
if (isset($_GET['status']) || isset($_GET['log'])) {
    $helper = isset($_GET['status']) ? 'pmssCustomerAppsStatusJsonEmit' : 'pmssCustomerAppsLogJsonEmit';
    if (!function_exists($helper)) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(array('updating' => true, 'message' => 'This page is being updated on this server. Reload it in a few minutes.'));
        return;
    }
    $helper();
    return;
}
if (!is_file(__DIR__.'/appsRuntime.php') || !is_file(__DIR__.'/configureSettings.php')) {
    echo '<!doctype html><html lang="en"><body><p>This page is being updated on this server. Reload it in a few minutes.</p></body></html>';
    return;
}
require_once __DIR__.'/configureSettings.php';
require_once __DIR__.'/appsRuntime.php';
if (!function_exists('pmssAppsRuntimeReady') || !pmssAppsRuntimeReady()
    || !function_exists('pmssFrontendPostActionRequired')) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'message' => 'This page is being updated on this server. Reload it in a few minutes.'));
    } else {
        echo '<!doctype html><html lang="en"><body><p>This page is being updated on this server. Reload it in a few minutes.</p></body></html>';
    }
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    pmssFrontendPostActionRequired();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $result = pmssConfigureActionApply(dirname(__DIR__), $action, $_POST);
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('ok' => $result['ok'], 'message' => $result['message']));
    return;
}
require_once __DIR__.'/userMediaStackPanel.php';

list($home, $username, $hostname) = pmssCustomerAccountContext();
$status = pmssCustomerAppsStatusRead($home, $username, $hostname);
$mediaStackStatus = function_exists('pmssMediaStackPanelStatusRead') ? pmssMediaStackPanelStatusRead($home, $username, $hostname) : array();
$usageAlertsEnabled = is_file($home.'/.usageAlertsEnabled') && !is_link($home.'/.usageAlertsEnabled') && (@fileperms($home.'/.usageAlertsEnabled') & 0777) === 0600;
$guiFramesLocalOnly = is_file($home.'/.guiFramesLocalOnly') && !is_link($home.'/.guiFramesLocalOnly');
$settingsState = pmssConfigureSettingsStateRead($home);
$customRows = $settingsState['customRows'];
$rtorrentCustom = $settingsState['rtorrentCustom'];
$certMode = $settingsState['certMode'];
$certFailed = $settingsState['certFailed'];
$sections = array('apps' => 'Apps', 'alerts' => 'Usage alert email', 'logins' => 'Passwords and logins',
    'backup' => 'Backup and restore', 'https' => 'HTTPS for your public folder', 'tabs' => 'Custom tabs',
    'rtorrent' => 'rTorrent custom settings', 'panel' => 'Panel', 'more' => 'Find more apps');
if (is_file(__DIR__.'/mediaStack.php') && function_exists('pmssMediaStackPanelHtmlBuild')) {
    $sections = array_merge(array_slice($sections, 0, 1, true), array('media' => 'Media Stack'), array_slice($sections, 1, null, true));
}
$urls = pmssAppsAllowedUrlsRead();
$lsioCatalog = is_file(__DIR__.'/appsLsioCatalog.php') ? require __DIR__.'/appsLsioCatalog.php' : array();
if (!is_array($lsioCatalog)) $lsioCatalog = array();
$torrentVersions = array();
$torrentRunning = '';
$torrentNotice = '';
if (is_file(__DIR__.'/configureTorrentClients.php') && is_file(__DIR__.'/torrentClientsResolve.php')) {
    require_once __DIR__.'/configureTorrentClients.php';
    $torrentVersions = pmssConfigureTorrentClientVersionsRead();
    $torrentRunning = pmssConfigureTorrentClientRunningVersion($home);
    $torrentNotice = trim(pmssConfigureFileRead($home.'/.config/pmss/torrentClients/qbittorrent.notice', 256));
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configure</title>
<link href="screen.css" rel="stylesheet" media="screen">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script><script><?= pmssActionScriptJs() ?></script>
<?= pmssCustomerAppsCss() ?>
<style>
.apps{font-size:14px;line-height:1.45;max-width:980px;margin:0 auto}.apps h1{font-size:1.5rem;margin:4px 0 14px}.apps>h2{font-size:1.05rem;color:#fff;margin:24px 0 8px}
.apps>.list,.lsio-group .list{border:1px solid #2e3b4f;border-radius:8px;overflow:hidden}
.lsio-group .group{color:#9fb0c3;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 4px}
.search{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:8px;border:1px solid #2e3b4f;background:#0b1220;color:#e6edf3;font-size:14px;margin:4px 0 10px}.note{color:#9fb0c3;font-size:13px;margin:0 0 8px}
.mrow{display:flex;gap:12px;align-items:baseline;padding:7px 14px;border-top:1px solid #223042;background:#0f1b2b}.mrow:first-child{border-top:0}.mrow .name{font-weight:bold;color:#fff;min-width:170px}.mrow .desc{flex:1;color:#9fb0c3;font-size:13px}.mrow .tag{font-size:11px;color:#f0b429;margin-left:6px}.mrow a{white-space:nowrap;font-size:13px}
@media(max-width:640px){.mrow{flex-wrap:wrap}.mrow .name{min-width:0}}
.configure-layout{display:grid;grid-template-columns:200px minmax(0,1fr);gap:20px}.configure-index{position:sticky;top:12px;align-self:start;display:flex;flex-direction:column;gap:5px}.configure-index a{padding:5px 9px;white-space:nowrap}.configure-card{background:#0f1b2b;border:1px solid #2e3b4f;border-radius:8px;padding:16px;margin-bottom:16px}.configure-card h2{color:#fff;margin-top:0}.configure-card p,.configure-card summary{color:#9fb0c3}.configure-card input,.configure-card textarea{max-width:100%;box-sizing:border-box}.configure-card code{display:block;overflow:auto;margin:5px 0}.configure-row{display:flex;gap:5px;flex-wrap:wrap;margin:6px 0}.configure-row input{min-width:0}.configure-row .url{flex:1 1 230px}
@media(max-width:760px){.configure-layout{display:block}.configure-index{position:sticky;top:0;z-index:2;flex-direction:row;overflow-x:auto;background:#0f1b2b;padding:7px 0;margin-bottom:12px}}
.configure-index a{padding:6px 10px;border-radius:6px;color:#cfe3f5;text-decoration:none;white-space:nowrap}.configure-index a:hover,.configure-index a:focus{background:#1b2c42;color:#fff}.configure-card button,.configure-card input[type=button],.configure-card input[type=submit]{background:#13708a;color:#fff;border:1px solid #1b8aa8;border-radius:6px;padding:7px 12px;font-size:13px;cursor:pointer;margin:2px 4px 2px 0}.configure-card button:hover{background:#178aa9}.configure-card input[type=text],.configure-card input:not([type]),.configure-card input[type=password],.configure-card textarea{background:#0b1220;color:#e6edf3;border:1px solid #2e3b4f;border-radius:6px;padding:7px 10px;font-size:13px}.configure-card textarea{font-family:monospace;width:100%}.configure-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:10px 0 0}.configure-actions input[type=password]{flex:0 1 240px}.configure-card h3{color:#e6edf3;font-size:1rem;margin:18px 0 6px}details.catalog{margin:10px 0}details.catalog>summary{cursor:pointer;color:#fff;font-weight:bold;font-size:1.05rem;padding:6px 0}
</style></head><body><div id="pmss-action-notice" role="status" aria-live="polite"></div>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body"><div class="apps">
<h1>Configure</h1><p class="note">Apps and settings for your account.</p>
<div class="configure-layout"><nav class="configure-index" aria-label="Configure sections"><?php foreach ($sections as $id => $label): ?><a href="#<?= pmssCustomerHtmlAttr($id) ?>"><?= pmssCustomerHtmlAttr($label) ?></a><?php endforeach; ?></nav><main>
<section class="configure-card" id="apps"><h2>Apps</h2><?= pmssCustomerAppsSectionHtmlBuild($status, 'configure.php?status=1') ?>
<?php if ($torrentVersions): ?><h3>qBittorrent version</h3><p>Running: <?= pmssCustomerHtmlAttr($torrentRunning) ?></p><?php if ($torrentNotice !== ''): ?><p role="status"><?= pmssCustomerHtmlAttr($torrentNotice) ?></p><?php endif; ?>
<div class="configure-actions"><select id="configure-qbittorrent-version" aria-label="qBittorrent version"><?php foreach ($torrentVersions as $version => $entry): ?><option value="<?= pmssCustomerHtmlAttr($version) ?>"><?= pmssCustomerHtmlAttr($version) ?></option><?php endforeach; ?></select>
<input type="password" id="configure-qbittorrent-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('torrentClientSwitch',{client:'qbittorrent',version:document.getElementById('configure-qbittorrent-version').value,password:document.getElementById('configure-qbittorrent-password').value},'Back up the current configuration and restart qBittorrent with this version?')">Switch version</button></div><?php endif; ?></section>
<?php if (isset($sections['media'])): ?><section class="configure-card" id="media"><h2>Media Stack</h2><div id="pmss-media-stack-status"><?= pmssMediaStackPanelHtmlBuild($mediaStackStatus) ?></div><details><summary>Advanced (SSH)</summary><p>Reruns and uninstall are done over SSH so you can review existing data first.</p><code>bash ~/install-media-stack.sh</code><code>bash ~/install-media-stack.sh --self-update</code><code>bash ~/install-media-stack.sh --uninstall</code><code>bash ~/install-media-stack.sh --force</code><p>Run <code style="display:inline">bash ~/install-media-stack.sh --help</code> for every option.</p></details></section><?php endif; ?>
<section class="configure-card" id="alerts"><h2>Usage alert email</h2><p>Opt in to email when traffic reaches 80% of the monthly allowance, disk usage reaches 90% of quota, or a monitored media-stack service is reported down. Alerts reset after the condition clears.</p><button type="button" onclick="pmssConfigurePreference(this,'usageAlertsMode','<?= $usageAlertsEnabled ? 'disabled' : 'enabled' ?>')"><?= $usageAlertsEnabled ? 'Disable usage alert email' : 'Enable usage alert email' ?></button></section>
<section class="configure-card" id="logins"><h2>Passwords and logins</h2><p>Deluge Web UI password: <b><?php if (is_file(__DIR__.'/userPasswords.php')) { require_once __DIR__.'/userPasswords.php'; echo pmssCustomerHtmlAttr(pmssDelugeAuthReadLocalclientPassword($home.'/.config/deluge/auth')); } else { echo 'Unavailable'; } ?></b> (also used for the daemon connection; separate from your account password)</p><?php if (function_exists('pmssDelugeServicePasswordRotate')): ?><button type="button" onclick="pmssConfigureSend('delugeRotate',{},'Deluge clients using the old password will need the new one. Rotate it?')">Rotate Deluge password</button><?php endif; ?><p>rclone password is the same as your web access password.</p><p>qBittorrent username is your own username. Existing setups may use your account password; if the panel prompts you, set a separate WebUI password and use it for future logins.</p></section>
<section class="configure-card" id="backup"><h2>Backup and restore</h2><p>Download rTorrent, ruTorrent, Deluge, and qBittorrent configuration plus torrent session/resume state. Media files are not included.</p><p><b>Keep the archive private:</b> it can contain private tracker URLs and separate application credentials.</p><form method="get" action="welcome.php"><input type="hidden" name="backup" value="download"><button type="submit">Download torrent configuration backup</button></form><p>Upload the backup with File Manager, stop your torrent client, then restore the uploaded archive path here.</p><input id="configure-archive" placeholder="pmss-torrent-config-YYYYMMDD-HHMMSS.tar.gz"><button type="button" onclick="pmssConfigureSend('restore',{archivePath:document.getElementById('configure-archive').value},'Restore replaces your current torrent client configuration; stop the client first. Continue?')">Restore torrent configuration</button></section>
<section class="configure-card" id="https"><h2>HTTPS for your public folder</h2><p>Current request: <?= pmssCustomerHtmlAttr($certMode === '' ? 'none' : $certMode) ?><?= $certFailed ? ' (previous request failed)' : '' ?></p><p>The default certificate covers only your username-free service permalink hostname.</p><button type="button" onclick="pmssConfigureSend('webCertsDefault',{},'Request a certificate for your service permalink?')">Request certificate</button><h3>Also include my username hostname</h3><p>This permanently publishes the username hostname in public Certificate Transparency logs. Requesting the default later does not remove that record.</p><label><input type="checkbox" id="configure-ct"> I understand this hostname contains my username and will be listed publicly in Certificate Transparency logs</label><div class="configure-actions"><input type="password" id="configure-cert-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('webCertsUsername',{ctAcknowledged:document.getElementById('configure-ct').checked?1:0,password:document.getElementById('configure-cert-password').value},'Including your username permanently publishes it in Certificate Transparency logs. Continue?')">Include username hostname</button></div></section>
<section class="configure-card" id="tabs"><h2>Custom tabs</h2><p>Add links shown in the top panel tabs. Enter your account password to save.</p><div id="configure-rows"><?php foreach ($customRows as $row): ?><div class="configure-row"><input class="label" maxlength="40" aria-label="Tab label" value="<?= pmssCustomerHtmlAttr($row['label']) ?>"><input class="tooltip" maxlength="80" aria-label="Tab tooltip" value="<?= pmssCustomerHtmlAttr($row['tooltip']) ?>"><input class="url" aria-label="Tab URL" value="<?= pmssCustomerHtmlAttr($row['url']) ?>"><button type="button" onclick="this.parentNode.remove()">Remove</button></div><?php endforeach; ?></div><button type="button" onclick="pmssConfigureRowAdd()">Add tab</button><div class="configure-actions"><input type="password" id="configure-tabs-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureFramesSave()">Save custom tabs</button></div></section>
<section class="configure-card" id="rtorrent"><h2>rTorrent custom settings</h2><p>rTorrent reads this file when it starts. Restart rTorrent in Apps to apply it.</p><textarea id="configure-rtorrent" rows="12" style="width:100%" maxlength="16384"><?= pmssCustomerHtmlAttr($rtorrentCustom) ?></textarea><div class="configure-actions"><input type="password" id="configure-rtorrent-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('rtorrentCustom',{content:document.getElementById('configure-rtorrent').value,password:document.getElementById('configure-rtorrent-password').value},'Save executable rTorrent directives? The previous version will be kept.')">Save rTorrent settings</button> <button type="button" onclick="pmssConfigureSend('rtorrentRestore',{password:document.getElementById('configure-rtorrent-password').value},'Restore the previous rTorrent settings?')">Restore previous version</button></div></section>
<section class="configure-card" id="panel"><h2>Panel</h2><h3>Panel frame source</h3><p>Remote-updated frame definitions are the default. Local definitions avoid that frame-definition request, but receive navigation changes only with server PMSS updates. Other panel content may still use remote resources.</p><button type="button" onclick="pmssConfigurePreference(this,'guiFramesMode','<?= $guiFramesLocalOnly ? 'remote' : 'local' ?>')"><?= $guiFramesLocalOnly ? 'Use remote-updated frames' : 'Use local frame definitions' ?></button><?php if (is_file(__DIR__.'/console.php') && is_file('/usr/bin/ttyd')): ?><h3>Shell console</h3><p>Use the Console tab for a shell in your browser. For a session that keeps running after you close the panel, open a persistent tmux session.</p><a href="console.php?mode=persist" target="_blank" rel="noopener">Persistent (tmux)</a><?php endif; ?><?php if (file_exists('openvpn-config.tgz')): ?><h3>OpenVPN ** Beta</h3><p>OpenVPN support has been added. You can download configuration below. Install OpenVPN from <a href="https://openvpn.net/download-open-vpn/">OpenVPN.net</a>.</p><p>You can open the tarball using WinRAR for example. Put the config files under OpenVPN config dir, e.g. <i>C:\Program Files\OpenVPN\config</i>. Login is the same as FTP/SFTP.</p><p><a href="openvpn-config.tgz">OpenVPN Config Files</a>.</p><?php endif; ?></section>
<section class="configure-card" id="more"><h2>Find more apps</h2><p class="note">You can set up more apps yourself with rootless Docker. LinuxServer.io examples publish ports on every address (for example 8080:8080). On this server that puts the app on the internet: publish it as 127.0.0.1:8080:8080 instead, and turn on the app's own login — other accounts on this server can reach a port on 127.0.0.1.</p>
<input id="pmss-apps-search" class="search" type="search" aria-label="Search apps" placeholder="Search apps, e.g. comics, photos, IRC">
<details class="catalog" id="pmss-more-apps-box"><summary>Guides on the Pulsed Media wiki</summary><div class="list" id="pmss-more-apps">
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?><div class="mrow" data-category="<?= pmssCustomerHtmlAttr($category) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app[0]) ?><?php if ($app[3] !== ''): ?><span class="tag"><?= pmssCustomerHtmlAttr($app[3]) ?></span><?php endif; ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app[2]) ?></span><a href="<?= pmssCustomerHtmlAttr($urls[$category]) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div><?php endforeach; endforeach; ?><div class="mrow" data-category="AI agents"><span class="name">OpenClaw<span class="tag">over SSH</span></span><span class="desc">Self-hosted AI agent for your account; install with <code style="display:inline">install-openclaw install</code></span><a href="https://wiki.pulsedmedia.com/index.php/OpenClaw_on_PMSS" target="_blank" rel="noopener">Setup guide &#8599;</a></div><div class="mrow" data-category="AI agents"><span class="name">AI coding tools<span class="tag">over SSH</span></span><span class="desc">Command-line AI coding assistants; personal copies with <code style="display:inline">bash ~/install-ai-tools.sh</code></span><a href="https://wiki.pulsedmedia.com/index.php/AI_Coding_Tools_on_PMSS" target="_blank" rel="noopener">Setup guide &#8599;</a></div></div></details>
<?php if ($lsioCatalog): ?><details class="catalog" id="pmss-lsio-box"><summary>More from LinuxServer.io (<?= count($lsioCatalog) ?>)</summary><div id="pmss-lsio-apps">
<?php $lastCategory = null; foreach ($lsioCatalog as $app): if ($app['category'] !== $lastCategory): if ($lastCategory !== null): ?></div></div><?php endif; $lastCategory = $app['category']; ?><div class="lsio-group"><div class="group"><?= pmssCustomerHtmlAttr(str_replace(',', ', ', $lastCategory)) ?></div><div class="list"><?php endif; ?>
<div class="mrow" data-category="<?= pmssCustomerHtmlAttr(str_replace(',', ', ', $app['category'])) ?>" data-search="<?= pmssCustomerHtmlAttr($app['name']) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app['title']) ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app['description']) ?></span><a href="<?= pmssCustomerHtmlAttr($app['guide']) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div>
<?php endforeach; ?></div></div></div></details><?php endif; ?>
</section></main></div></div></div><div class="full_bottom"></div></div></div>
<script>
function pmssConfigureNotice(message, error) { var node = document.getElementById('pmss-action-notice'); node.textContent = message; node.style.display = 'block'; node.className = error ? 'pmss-error' : ''; }
function pmssConfigureSend(action, fields, consequence) {
    if (consequence && !window.confirm(consequence)) return;
    fields.action = action;
    pmssConfigureNotice('Saving...', false);
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},data:fields,
        success:function(result){pmssConfigureNotice(result.message || 'Saved.', false); if (action === 'delugeRotate' || action === 'torrentClientSwitch') setTimeout(function(){location.reload(true);}, 1500);},
        error:function(xhr){pmssConfigureNotice((xhr.responseJSON && xhr.responseJSON.message) || 'The setting could not be saved.', true);}});
}
function pmssConfigurePreference(button, key, mode) {
    var fields = {}; fields[key] = mode;
    $.ajax({url:'index.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},data:fields,
        success:function(){pmssConfigureNotice('Saved. Reloading...', false); setTimeout(function(){if (key === 'guiFramesMode') window.top.location.reload(true); else location.reload(true);},500);},
        error:function(){pmssConfigureNotice('The preference could not be saved.', true);}});
}
function pmssConfigureRowAdd() {
    var container = document.getElementById('configure-rows');
    if (container.children.length >= 32) return;
    var row = document.createElement('div'); row.className = 'configure-row';
    row.innerHTML = '<input class="label" maxlength="40" aria-label="Tab label" placeholder="Label"><input class="tooltip" maxlength="80" aria-label="Tab tooltip" placeholder="Tooltip"><input class="url" aria-label="Tab URL" placeholder="https://example.com/"><button type="button" onclick="this.parentNode.remove()">Remove</button>';
    container.appendChild(row);
}
function pmssConfigureFramesSave() {
    var rows = []; $('#configure-rows .configure-row').each(function(){rows.push({label:$(this).find('.label').val(),tooltip:$(this).find('.tooltip').val(),url:$(this).find('.url').val()});});
    pmssConfigureSend('customFrames',{rows:rows,password:document.getElementById('configure-tabs-password').value},'Save custom tabs? Their URLs will open from your panel.');
}
$('#pmss-apps-search').on('input', function() {
    var query = this.value.toLowerCase().trim(), compactQuery = query.replace(/[\s-]/g, '');
    $('#pmss-more-apps .mrow, #pmss-lsio-apps .mrow').each(function() {
        var row = $(this), search = (row.find('.name, .desc').text() + ' ' + row.attr('data-category') + ' ' + (row.attr('data-search') || '')).toLowerCase();
        row.toggle(search.indexOf(query) !== -1 || search.replace(/[\s-]/g, '').indexOf(compactQuery) !== -1);
    });
    $('#pmss-lsio-apps .lsio-group').each(function() { $(this).toggle($(this).find('.mrow:visible').length > 0); });
    $('details.catalog').prop('open', query !== '');
});
</script></body></html>
