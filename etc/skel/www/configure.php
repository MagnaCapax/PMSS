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
if (isset($_GET['hermesStatus'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(function_exists('pmssHermesPanelStatusRead')
        ? pmssHermesPanelStatusRead(dirname(__DIR__)) : array('installed' => false, 'state' => ''));
    return;
}
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
$pmssPikkumiesState = function_exists('pmssPikkumiesPanelStateRead')
    ? pmssPikkumiesPanelStateRead($home, $username)
    : array('visible' => false, 'origin' => '', 'mode' => 'simplified', 'off' => false);
$pmssHermesState = function_exists('pmssHermesPanelStatusRead') ? pmssHermesPanelStatusRead($home)
    : array('installed' => false, 'state' => '', 'url' => '');
$pmssHermesChoice = trim(pmssConfigureFileRead($home.'/.config/pmss/ui/hermes-interface', 64));
if (!in_array($pmssHermesChoice, array('vanilla-hermes-web', 'vanilla-agentboard', 'agentboard-pikkumies'), true))
    $pmssHermesChoice = 'vanilla-hermes-web';
// Hermes install state => pill text, pill colour class, hover hint. The status poll uses the same map.
$pmssHermesPills = array('' => array('Not installed', 'p-off', ''), 'installing' => array('Installing…', 'p-warn', ''),
    'failed' => array('Install failed', 'p-stop', ''),
    'customer-updated' => array('Left unchanged', 'p-warn', 'Your Hermes copy has your own changes, so it was left as it is.'));
$customRows = $settingsState['customRows'];
$rtorrentCustom = $settingsState['rtorrentCustom'];
$certMode = $settingsState['certMode'];
$certFailed = $settingsState['certFailed'];
$sections = array('apps' => 'Apps', 'alerts' => 'Usage alert email', 'logins' => 'Passwords and logins',
    'backup' => 'Backup and restore', 'https' => 'HTTPS for your public folder', 'tabs' => 'Custom tabs',
    'rtorrent' => 'rTorrent custom settings', 'panel' => 'Panel', 'more' => 'Find more apps');
if (function_exists('pmssAgentsSectionVisible') && pmssAgentsSectionVisible($pmssPikkumiesState, $home)) {
    $sections = array_merge(array_slice($sections, 0, 1, true), array('agents' => 'AI agents'), array_slice($sections, 1, null, true));
}
if (is_file(__DIR__.'/mediaStack.php') && function_exists('pmssMediaStackPanelHtmlBuild')) {
    $sections = array_merge(array_slice($sections, 0, 1, true), array('media' => 'Media Stack'), array_slice($sections, 1, null, true));
}
$ownerCards = function_exists('pmssAppOwnerSettingsCards') ? pmssAppOwnerSettingsCards($home, $status) : array();
foreach ($ownerCards as $cardId => $card) {
    $sections = array_merge(array_slice($sections, 0, 1, true), array($cardId => $card['label']), array_slice($sections, 1, null, true));
}
$reloadActions = array_merge(array('delugeRotate', 'torrentClientSwitch'), function_exists('pmssAppOwnerSettingsActions') ? pmssAppOwnerSettingsActions() : array());
$urls = pmssAppsAllowedUrlsRead();
$lsioCatalog = is_file(__DIR__.'/appsLsioCatalog.php') ? require __DIR__.'/appsLsioCatalog.php' : array();
if (!is_array($lsioCatalog)) $lsioCatalog = array();
$torrentVersions = array();
$torrentRunning = '';
$torrentNotice = '';
$otherTorrentVersions = array();
if (is_file(__DIR__.'/configureTorrentClients.php') && is_file(__DIR__.'/torrentClientsResolve.php')) {
    require_once __DIR__.'/configureTorrentClients.php';
    $torrentVersions = pmssConfigureTorrentClientVersionsOffered();
    $torrentRunning = pmssConfigureTorrentClientRunningVersion($home);
    $torrentNotice = trim(pmssConfigureFileRead($home.'/.config/pmss/torrentClients/qbittorrent.notice', 256));
    if (is_file(__DIR__.'/configureTorrentClientsOther.php') && is_file(__DIR__.'/configureTorrentClientsSwitch.php')
        && is_file(__DIR__.'/transmissionLaunch.php')) {
        require_once __DIR__.'/configureTorrentClientsOther.php';
        foreach (array('deluge', 'transmission', 'rtorrent') as $client)
            $otherTorrentVersions[$client] = pmssConfigureTorrentClientVersionsOffered($client);
    }
}
// List the version controls in the section index right after Apps.
$versionSections = array();
if ($torrentVersions) $versionSections['qbittorrent-version'] = 'qBittorrent version';
foreach (array('deluge' => 'Deluge', 'transmission' => 'Transmission', 'rtorrent' => 'rTorrent') as $client => $label)
    if (!empty($otherTorrentVersions[$client])) $versionSections[$client.'-version'] = $label.' version';
$sections = array_merge(array_slice($sections, 0, 1, true), $versionSections, array_slice($sections, 1, null, true));
?>
<!doctype html>
<html lang="en"><head><script>var u = new URL(location.href); if (u.username || u.password) { u.username = ''; u.password = ''; location.replace(u.href); }</script><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configure</title>
<link href="screen.css" rel="stylesheet" media="screen">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script><script><?= pmssActionScriptJs() ?></script>
<?= pmssCustomerAppsCss() ?>
<style>
<?= pmssAppsCatalogCss() ?>
.configure-layout{display:grid;grid-template-columns:200px minmax(0,1fr);gap:20px}.configure-index{position:sticky;top:12px;align-self:start;display:flex;flex-direction:column;gap:5px}.configure-index a{padding:5px 9px;white-space:nowrap}.configure-card{background:#0f1b2b;border:1px solid #2e3b4f;border-radius:8px;padding:16px;margin-bottom:16px}.configure-card h2{color:#fff;margin-top:0}.configure-card p,.configure-card summary{color:#9fb0c3}.configure-card input,.configure-card textarea{max-width:100%;box-sizing:border-box}.configure-card code{display:block;overflow:auto;margin:5px 0}.configure-row{display:flex;gap:5px;flex-wrap:wrap;margin:6px 0}.configure-row input{min-width:0}.configure-row .url{flex:1 1 230px}
@media(max-width:760px){.configure-layout{display:block}.configure-index{position:sticky;top:0;z-index:2;flex-direction:row;overflow-x:auto;background:#0f1b2b;padding:7px 0;margin-bottom:12px}}
.configure-index a{padding:6px 10px;border-radius:6px;color:#cfe3f5;text-decoration:none;white-space:nowrap}.configure-index a:hover,.configure-index a:focus{background:#1b2c42;color:#fff}.configure-card button,.configure-card input[type=button],.configure-card input[type=submit]{background:#13708a;color:#fff;border:1px solid #1b8aa8;border-radius:6px;padding:7px 12px;font-size:13px;cursor:pointer;margin:2px 4px 2px 0}.configure-card button:hover{background:#178aa9}.configure-card input[type=text],.configure-card input:not([type]),.configure-card input[type=password],.configure-card textarea{background:#0b1220;color:#e6edf3;border:1px solid #2e3b4f;border-radius:6px;padding:7px 10px;font-size:13px}.configure-card textarea{font-family:monospace;width:100%}.configure-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:10px 0 0}.configure-actions input[type=password]{flex:0 1 240px}.configure-card h3{color:#e6edf3;font-size:1rem;margin:18px 0 6px}details.catalog{margin:10px 0}details.catalog>summary{cursor:pointer;color:#fff;font-weight:bold;font-size:1.05rem;padding:6px 0}
.configure-card select{background:#0b1220;color:#e6edf3;border:1px solid #2e3b4f;border-radius:6px;padding:6px 8px;font-size:13px;max-width:100%;color-scheme:dark}.pmss-agents-section .row label.name{display:block}.pmss-agents-signin{margin:12px 0 0}.pmss-agents-signin summary{cursor:pointer}.pmss-agents-signin p{margin:8px 0}
</style></head><body><div id="pmss-action-notice" role="status" aria-live="polite"></div>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body"><div class="apps">
<h1>Configure</h1><p class="note">Apps and settings for your account.</p>
<div class="configure-layout"><nav class="configure-index" aria-label="Configure sections"><?php foreach ($sections as $id => $label): ?><a href="#<?= pmssCustomerHtmlAttr($id) ?>"><?= pmssCustomerHtmlAttr($label) ?></a><?php endforeach; ?></nav><main>
<section class="configure-card" id="apps"><h2>Apps</h2><?= pmssCustomerAppsSectionHtmlBuild($status, 'configure.php?status=1') ?></section>
<?php if ($torrentVersions): ?><section class="configure-card" id="qbittorrent-version"><h2>qBittorrent version</h2><p>Running: <?= pmssCustomerHtmlAttr(pmssConfigureTorrentClientVersionLabel($torrentRunning)) ?></p><?php if ($torrentNotice !== ''): ?><p role="status"><?= pmssCustomerHtmlAttr($torrentNotice) ?></p><?php endif; ?>
<div class="configure-actions"><select id="configure-qbittorrent-version" aria-label="qBittorrent version"><?php $selectedVersion = pmssConfigureTorrentClientSelectedVersion($home, 'qbittorrent'); foreach ($torrentVersions as $version => $entry): ?><option value="<?= pmssCustomerHtmlAttr($version) ?>"<?= (string) $version === $selectedVersion ? ' selected' : '' ?>><?= pmssCustomerHtmlAttr(pmssConfigureTorrentClientVersionLabel($version)) ?></option><?php endforeach; ?></select>
<input type="password" id="configure-qbittorrent-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('torrentClientSwitch',{client:'qbittorrent',version:document.getElementById('configure-qbittorrent-version').value,password:document.getElementById('configure-qbittorrent-password').value},'Back up the current configuration and restart qBittorrent with this version?')">Switch version</button></div></section><?php endif; ?>
<?php foreach (array('deluge' => 'Deluge', 'transmission' => 'Transmission', 'rtorrent' => 'rTorrent') as $client => $label): $versions = $otherTorrentVersions[$client] ?? array(); if ($versions): ?>
<section class="configure-card" id="<?= $client ?>-version"><h2><?= $label ?> version</h2><p>Running: <?= pmssCustomerHtmlAttr(pmssConfigureTorrentClientOtherRunningVersion($home, $client)) ?></p><?php $notice = trim(pmssConfigureFileRead($home.'/.config/pmss/torrentClients/'.$client.'.notice', 256)); if ($notice !== ''): ?><p role="status"><?= pmssCustomerHtmlAttr($notice) ?></p><?php endif; ?>
<div class="configure-actions"><select id="configure-<?= $client ?>-version" aria-label="<?= $label ?> version"><?php $selectedVersion = pmssConfigureTorrentClientSelectedVersion($home, $client); foreach ($versions as $version => $entry): ?><option value="<?= pmssCustomerHtmlAttr($version) ?>"<?= (string) $version === $selectedVersion ? ' selected' : '' ?>><?= pmssCustomerHtmlAttr(pmssConfigureTorrentClientVersionLabel($version)) ?></option><?php endforeach; ?></select>
<input type="password" id="configure-<?= $client ?>-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('torrentClientSwitch',{client:'<?= $client ?>',version:document.getElementById('configure-<?= $client ?>-version').value,password:document.getElementById('configure-<?= $client ?>-password').value},'<?= $client === 'transmission' ? 'Restart Transmission with this version? Its settings are not part of the configuration backup.' : 'Back up the current configuration and restart '.$label.' with this version?' ?>')">Switch version</button></div></section><?php endif; endforeach; ?>
<?php foreach ($ownerCards as $cardId => $card): ?>
<section class="configure-card" id="<?= pmssCustomerHtmlAttr($cardId) ?>"><h2><?= pmssCustomerHtmlAttr($card['label']) ?></h2>
<?= $card['html'] ?></section>
<?php endforeach; ?>
<?php if (isset($sections['agents'])): ?>
<section class="configure-card" id="agents"><h2>AI agents</h2><div class="pmss-apps-section pmss-agents-section"><div class="list">
<?php if ($pmssPikkumiesState['origin'] !== ''): $pmssPikkumiesLabel = $pmssPikkumiesState['off'] ? 'Off' : 'Starting'; ?>
<div class="row"><div class="main"><div class="name">Pikkumies</div><div class="desc">A small helper on your server. Ask it in plain words to check disk space, fix a stuck app or add a torrent. Its windows keep running and pick up where you left off after a restart, until you close them.</div></div>
<span class="pill <?= $pmssPikkumiesState['off'] ? 'p-off' : 'p-warn' ?>" id="pmss-pikkumies-pill" data-state="<?= $pmssPikkumiesLabel ?>"><?= $pmssPikkumiesLabel ?></span>
<div class="acts"><?php if ($pmssPikkumiesState['visible']): ?><button type="button" class="b b-pri" onclick="pmssPikkumiesTabOpen()">Open</button>
<?php if ($pmssPikkumiesState['mode'] === 'power-user'): ?><a class="b" href="pikkumiesOpen.php?ui=hermes" target="_blank" rel="noopener">Open full Hermes</a><?php endif; ?>
<?php endif; ?><button type="button" class="b<?= $pmssPikkumiesState['off'] ? ' b-pri' : '' ?>" onclick="pmssPikkumiesToggle('<?= $pmssPikkumiesState['off'] ? 'on' : 'off' ?>')"><?= $pmssPikkumiesState['off'] ? 'Turn on' : 'Turn off' ?></button></div></div>
<?php endif; ?>
<div class="row" id="pmss-hermes-card" data-state="<?= pmssCustomerHtmlAttr($pmssHermesState['state']) ?>"><div class="main"><div class="name">Hermes</div><div class="desc">Your own Hermes agent, separate from Pikkumies.</div></div>
<?php if (!$pmssHermesState['installed']): $pmssHermesPill = $pmssHermesPills[$pmssHermesState['state']] ?? $pmssHermesPills['']; ?>
<span class="pill <?= $pmssHermesPill[1] ?>" id="pmss-hermes-pill" title="<?= pmssCustomerHtmlAttr($pmssHermesPill[2]) ?>"><?= $pmssHermesPill[0] ?></span>
<div class="acts"><button type="button" class="b b-pri" id="pmss-hermes-install" onclick="pmssHermesInstall()">Install</button></div>
<?php else: ?>
<div class="acts"><a class="b b-pri" href="pikkumiesOpen.php?ui=<?= pmssCustomerHtmlAttr($pmssHermesChoice) ?>" target="_blank" rel="noopener">Open</a></div>
<?php endif; ?></div></div>
<?php $pmssPikkumiesSignIn = $pmssPikkumiesState['visible'] && $pmssPikkumiesState['mode'] === 'power-user'; ?>
<?php if ($pmssPikkumiesState['origin'] !== '' || $pmssHermesState['installed']): ?><div class="group">Settings</div><div class="list">
<?php if ($pmssPikkumiesState['origin'] !== ''): ?><div class="row"><div class="main"><label class="name" for="pmss-pikkumies-interface">Pikkumies view</label></div>
<div class="acts"><select id="pmss-pikkumies-interface" onchange="pmssPikkumiesInterface(this.value)">
<option value="simplified"<?= $pmssPikkumiesState['mode'] === 'simplified' ? ' selected' : '' ?>>Simple (recommended)</option>
<option value="power-user"<?= $pmssPikkumiesState['mode'] === 'power-user' ? ' selected' : '' ?>>Full Hermes, in its own tab</option></select></div></div><?php endif; ?>
<?php if ($pmssHermesState['installed']): ?><div class="row"><div class="main"><label class="name" for="pmss-hermes-interface">Hermes opens in</label><div class="desc">Only one Hermes window works at a time: opening one closes the other.</div></div>
<div class="acts"><select id="pmss-hermes-interface" onchange="pmssHermesInterface(this.value)">
<option value="vanilla-hermes-web"<?= $pmssHermesChoice === 'vanilla-hermes-web' ? ' selected' : '' ?>>Hermes web UI (default)</option>
<option value="vanilla-agentboard"<?= $pmssHermesChoice === 'vanilla-agentboard' ? ' selected' : '' ?>>Agentboard</option>
<?php if ($pmssPikkumiesState['visible']): ?><option value="agentboard-pikkumies"<?= $pmssHermesChoice === 'agentboard-pikkumies' ? ' selected' : '' ?>>Agentboard Pikkumies</option><?php endif; ?></select></div></div><?php endif; ?>
</div><?php endif; ?>
<?php if ($pmssPikkumiesSignIn || $pmssHermesState['installed']): ?><details class="pmss-agents-signin"><summary>Sign in to Hermes yourself</summary>
<p>Enter your account password to see the Hermes password. <span class="pmss-hermes-user">User name: <b><?= pmssCustomerHtmlAttr($username) ?></b></span></p>
<?php if ($pmssPikkumiesSignIn): ?><div class="configure-actions"><input type="password" id="pmss-pikkumies-hermes-account-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssPikkumiesHermesShowLogin()">Show Pikkumies Hermes password</button><output id="pmss-pikkumies-hermes-password" hidden></output></div><?php endif; ?>
<?php if ($pmssHermesState['installed']): ?><div class="configure-actions"><input type="password" id="pmss-hermes-account-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssHermesShowLogin()">Show Hermes password</button>
<output id="pmss-hermes-password" hidden></output><button type="button" id="pmss-hermes-copy" hidden onclick="navigator.clipboard.writeText(document.getElementById('pmss-hermes-password').textContent)">Copy password</button></div><?php endif; ?>
</details><?php endif; ?>
</div></section>
<?php endif; ?>
<?php if (isset($sections['media'])): ?><section class="configure-card" id="media"><h2>Media Stack</h2><div id="pmss-media-stack-status"><?= pmssMediaStackPanelHtmlBuild($mediaStackStatus) ?></div><details><summary>Advanced (SSH)</summary><p>Reruns and uninstall are done over SSH so you can review existing data first.</p><code>bash ~/install-media-stack.sh</code><code>bash ~/install-media-stack.sh --self-update</code><code>bash ~/install-media-stack.sh --uninstall</code><code>bash ~/install-media-stack.sh --force</code><p>Run <code style="display:inline">bash ~/install-media-stack.sh --help</code> for every option.</p></details></section><?php endif; ?>
<section class="configure-card" id="alerts"><h2>Usage alert email</h2><p>Opt in to email when traffic reaches 80% of the monthly allowance, disk usage reaches 90% of quota, or a monitored media-stack service is reported down. Alerts reset after the condition clears.</p><button type="button" onclick="pmssConfigurePreference(this,'usageAlertsMode','<?= $usageAlertsEnabled ? 'disabled' : 'enabled' ?>')"><?= $usageAlertsEnabled ? 'Disable usage alert email' : 'Enable usage alert email' ?></button></section>
<section class="configure-card" id="logins"><h2>Passwords and logins</h2><p>Deluge Web UI password: <b><?php if (is_file(__DIR__.'/userPasswords.php')) { require_once __DIR__.'/userPasswords.php'; echo pmssCustomerHtmlAttr(pmssDelugeAuthReadLocalclientPassword($home.'/.config/deluge/auth')); } else { echo 'Unavailable'; } ?></b> (also used for the daemon connection; separate from your account password)</p><?php if (function_exists('pmssDelugeServicePasswordRotate')): ?><button type="button" onclick="pmssConfigureSend('delugeRotate',{},'Deluge clients using the old password will need the new one. Rotate it?')">Rotate Deluge password</button><?php endif; ?><p>rclone password is the same as your web access password.</p><p>qBittorrent username is your own username. Existing setups may use your account password; if the panel prompts you, set a separate WebUI password and use it for future logins.</p></section>
<section class="configure-card" id="backup"><h2>Backup and restore</h2><p>Download rTorrent, ruTorrent, Deluge, and qBittorrent configuration plus torrent session/resume state. Media files are not included.</p><p><b>Keep the archive private:</b> it can contain private tracker URLs and separate application credentials.</p><form method="get" action="welcome.php"><input type="hidden" name="backup" value="download"><button type="submit">Download torrent configuration backup</button></form><p>Upload the backup with File Manager, stop your torrent client, then restore the uploaded archive path here.</p><input id="configure-archive" placeholder="pmss-torrent-config-YYYYMMDD-HHMMSS.tar.gz"><button type="button" onclick="pmssConfigureSend('restore',{archivePath:document.getElementById('configure-archive').value},'Restore replaces your current torrent client configuration; stop the client first. Continue?')">Restore torrent configuration</button></section>
<section class="configure-card" id="https"><h2>HTTPS for your public folder</h2><p>Current request: <?= pmssCustomerHtmlAttr($certMode === '' ? 'none' : $certMode) ?><?= $certFailed ? ' (previous request failed)' : '' ?></p><p>The default certificate covers only your username-free service permalink hostname.</p><button type="button" onclick="pmssConfigureSend('webCertsDefault',{},'Request a certificate for your service permalink?')">Request certificate</button><h3>Also include my username hostname</h3><p>This permanently publishes the username hostname in public Certificate Transparency logs. Requesting the default later does not remove that record.</p><label><input type="checkbox" id="configure-ct"> I understand this hostname contains my username and will be listed publicly in Certificate Transparency logs</label><div class="configure-actions"><input type="password" id="configure-cert-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('webCertsUsername',{ctAcknowledged:document.getElementById('configure-ct').checked?1:0,password:document.getElementById('configure-cert-password').value},'Including your username permanently publishes it in Certificate Transparency logs. Continue?')">Include username hostname</button></div></section>
<section class="configure-card" id="tabs"><h2>Custom tabs</h2><p>Add links shown in the top panel tabs. Position sets the order after Welcome: higher sits further left. Enter your account password to save.</p><div id="configure-rows"><?php foreach ($customRows as $row): ?><div class="configure-row"><input class="label" maxlength="40" aria-label="Tab label" value="<?= pmssCustomerHtmlAttr($row['label']) ?>"><input class="tooltip" maxlength="80" aria-label="Tab tooltip" value="<?= pmssCustomerHtmlAttr($row['tooltip']) ?>"><input class="url" aria-label="Tab URL" value="<?= pmssCustomerHtmlAttr($row['url']) ?>"><input class="position" inputmode="numeric" maxlength="3" size="4" aria-label="Tab position" title="Position: -99 to 99, higher sits further left. Empty keeps it among the other tabs." placeholder="Pos." value="<?= pmssCustomerHtmlAttr($row['position'] ?? '') ?>"><button type="button" onclick="this.parentNode.remove()">Remove</button></div><?php endforeach; ?></div><button type="button" onclick="pmssConfigureRowAdd()">Add tab</button><div class="configure-actions"><input type="password" id="configure-tabs-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureFramesSave()">Save custom tabs</button></div></section>
<section class="configure-card" id="rtorrent"><h2>rTorrent custom settings</h2><p>rTorrent reads this file when it starts. Restart rTorrent in Apps to apply it.</p><textarea id="configure-rtorrent" rows="12" style="width:100%" maxlength="16384"><?= pmssCustomerHtmlAttr($rtorrentCustom) ?></textarea><div class="configure-actions"><input type="password" id="configure-rtorrent-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('rtorrentCustom',{content:document.getElementById('configure-rtorrent').value,password:document.getElementById('configure-rtorrent-password').value},'Save executable rTorrent directives? The previous version will be kept.')">Save rTorrent settings</button> <button type="button" onclick="pmssConfigureSend('rtorrentRestore',{password:document.getElementById('configure-rtorrent-password').value},'Restore the previous rTorrent settings?')">Restore previous version</button></div></section>
<section class="configure-card" id="panel"><h2>Panel</h2><h3>Panel frame source</h3><p>Remote-updated frame definitions are the default. Local definitions avoid that frame-definition request, but receive navigation changes only with server PMSS updates. Other panel content may still use remote resources.</p><button type="button" onclick="pmssConfigurePreference(this,'guiFramesMode','<?= $guiFramesLocalOnly ? 'remote' : 'local' ?>')"><?= $guiFramesLocalOnly ? 'Use remote-updated frames' : 'Use local frame definitions' ?></button><?php if (is_file(__DIR__.'/console.php') && is_file('/usr/bin/ttyd')): ?><h3>Shell console</h3><p>Use the Console tab for a shell in your browser. For a session that keeps running after you close the panel, open a persistent tmux session.</p><a href="console.php?mode=persist" target="_blank" rel="noopener">Persistent (tmux)</a><?php endif; ?><?php if (file_exists('openvpn-config.tgz')): ?><h3>OpenVPN ** Beta</h3><p>OpenVPN support has been added. You can download configuration below. Install OpenVPN from <a href="https://openvpn.net/download-open-vpn/">OpenVPN.net</a>.</p><p>You can open the tarball using WinRAR for example. Put the config files under OpenVPN config dir, e.g. <i>C:\Program Files\OpenVPN\config</i>. Login is the same as FTP/SFTP.</p><p><a href="openvpn-config.tgz">OpenVPN Config Files</a>.</p><?php endif; ?></section>
<section class="configure-card" id="more"><h2>Find more apps</h2><p class="note">You can set up more apps yourself with rootless Docker. LinuxServer.io examples publish ports on every address (for example 8080:8080). On this server that puts the app on the internet: publish it as 127.0.0.1:8080:8080 instead, and turn on the app's own login — other accounts on this server can reach a port on 127.0.0.1.</p>
<input id="pmss-apps-search" class="search" type="search" aria-label="Search apps" placeholder="Search apps, e.g. comics, photos, IRC">
<details class="catalog" id="pmss-more-apps-box"><summary>Guides on the Pulsed Media wiki</summary><div class="list" id="pmss-more-apps">
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?><div class="mrow" data-category="<?= pmssCustomerHtmlAttr($category) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app[0]) ?><?php if ($app[3] !== ''): ?><span class="tag"><?= pmssCustomerHtmlAttr($app[3]) ?></span><?php endif; ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app[2]) ?></span><a href="<?= pmssCustomerHtmlAttr($urls[$category]) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div><?php endforeach; endforeach; ?><div class="mrow" data-category="AI agents"><span class="name">AI coding tools<span class="tag">over SSH</span></span><span class="desc">Command-line AI coding assistants; personal copies with <code style="display:inline">bash ~/install-ai-tools.sh</code></span><a href="https://wiki.pulsedmedia.com/index.php/AI_Coding_Tools_on_PMSS" target="_blank" rel="noopener">Setup guide &#8599;</a></div></div></details>
<?php if ($lsioCatalog): ?><details class="catalog" id="pmss-lsio-box"><summary>More from LinuxServer.io (<?= count($lsioCatalog) ?>)</summary><div id="pmss-lsio-apps">
<?php $lastCategory = null; foreach ($lsioCatalog as $app): if ($app['category'] !== $lastCategory): if ($lastCategory !== null): ?></div></div><?php endif; $lastCategory = $app['category']; ?><div class="lsio-group"><div class="group"><?= pmssCustomerHtmlAttr(str_replace(',', ', ', $lastCategory)) ?></div><div class="list"><?php endif; ?>
<div class="mrow" data-category="<?= pmssCustomerHtmlAttr(str_replace(',', ', ', $app['category'])) ?>" data-search="<?= pmssCustomerHtmlAttr($app['name']) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app['title']) ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app['description']) ?></span><a href="<?= pmssCustomerHtmlAttr($app['guide']) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div>
<?php endforeach; ?></div></div></div></details><?php endif; ?>
</section></main></div></div></div><div class="full_bottom"></div></div></div>
<script>
function pmssConfigureNotice(message, error) { var node = document.getElementById('pmss-action-notice'); node.textContent = message; node.style.display = 'block'; node.className = error ? 'pmss-error' : ''; }
var pmssConfigureReloadActions = <?= json_encode($reloadActions) ?>;
function pmssConfigureSend(action, fields, consequence) {
    if (consequence && !window.confirm(consequence)) return;
    fields.action = action;
    pmssConfigureNotice('Saving...', false);
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},data:fields,
        success:function(result){pmssConfigureNotice(result.message || 'Saved.', false); if (pmssConfigureReloadActions.indexOf(action) >= 0) setTimeout(function(){location.reload(true);}, 1500);},
        error:function(xhr){pmssConfigureNotice((xhr.responseJSON && xhr.responseJSON.message) || 'The setting could not be saved.', true);},
        complete:function(){document.querySelectorAll('[data-pmss-clear]').forEach(function(node){node.value='';});}});
}
function pmssPikkumiesTabOpen() {
    try {
        var tab = window.parent.document.querySelector('a[href="#pikkumies"]');
        if (tab) { tab.click(); return; }
        window.top.location.reload(true);
    } catch (e) { location.href = 'pikkumiesOpen.php?session=pm1'; }
}
function pmssPikkumiesInterface(mode) {
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},
        data:{action:'pikkumiesInterface',mode:mode},
        success:function(){pmssConfigureNotice('Saved.', false); window.top.location.reload(true);},
        error:function(){pmssConfigureNotice('That did not go through. Try again.', true);}});
}
function pmssPikkumiesToggle(mode) {
    if (mode === 'off' && !window.confirm('Turn Pikkumies off? All its windows stop and stay stopped until you turn it back on.')) return;
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},
        data:{action:'pikkumiesEternal',mode:mode},
        success:function(){window.top.location.reload(true);},
        error:function(){pmssConfigureNotice('That did not go through. Try again.', true);}});
}
function pmssHermesInstall() {
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},
        data:{action:'hermesInstall'},
        success:function(){pmssConfigureNotice('Installing… This takes a few minutes.', false); pmssHermesStatusPoll();},
        error:function(xhr){pmssConfigureNotice((xhr.responseJSON && xhr.responseJSON.message) || 'Install did not finish. Try again, or contact support.', true);}});
}
function pmssHermesInterface(mode) {
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},
        data:{action:'hermesInterface',mode:mode},
        success:function(){pmssConfigureNotice('Saved.', false); location.reload(true);},
        error:function(){pmssConfigureNotice('That did not go through. Try again.', true);}});
}
function pmssHermesShowLogin() {
    var field = document.getElementById('pmss-hermes-account-password');
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},
        data:{action:'hermesShowLogin',password:field.value},
        success:function(result){var output=document.getElementById('pmss-hermes-password'); output.textContent=result.message; output.hidden=false; document.getElementById('pmss-hermes-copy').hidden=false; field.value='';},
        error:function(){pmssConfigureNotice('The password you entered was not accepted.', true); field.value='';}});
}
function pmssPikkumiesHermesShowLogin() {
    var field = document.getElementById('pmss-pikkumies-hermes-account-password');
    $.ajax({url:'configure.php',type:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},
        data:{action:'pikkumiesHermesShowLogin',password:field.value},
        success:function(result){var output=document.getElementById('pmss-pikkumies-hermes-password'); output.textContent=result.message; output.hidden=false; field.value='';},
        error:function(){pmssConfigureNotice('The password you entered was not accepted.', true); field.value='';}});
}
// Poll only while an install runs: reload once when it finishes, never on an already installed Hermes.
var pmssHermesPills = <?= json_encode($pmssHermesPills) ?>, pmssHermesPollTimer = null;
function pmssHermesPollLater() { clearTimeout(pmssHermesPollTimer); pmssHermesPollTimer = setTimeout(pmssHermesStatusPoll, 5000); }
function pmssHermesStatusPoll() {
    var card=document.getElementById('pmss-hermes-card');
    if (!card) return;
    if (document.hidden) { pmssHermesPollLater(); return; }
    fetch('configure.php?hermesStatus=1', {credentials:'same-origin',cache:'no-store'})
        .then(function(response){return response.json();}).then(function(state){
            if (state.installed) { location.reload(true); return; }
            card.setAttribute('data-state',state.state);
            var pill=document.getElementById('pmss-hermes-pill'), shown=pmssHermesPills[state.state] || pmssHermesPills[''];
            if (pill) { pill.textContent=shown[0]; pill.className='pill '+shown[1]; pill.title=shown[2]; }
            if (state.state === 'installing') pmssHermesPollLater();
        }).catch(pmssHermesPollLater);
}
(function(){ var card=document.getElementById('pmss-hermes-card'); if (card && card.getAttribute('data-state') === 'installing') pmssHermesPollLater(); })();
<?php if (isset($sections['agents'])): ?>
function pmssPikkumiesStatusPoll() {
    if (!document.getElementById('pmss-pikkumies-pill') || document.hidden
        || (window.frameElement && window.frameElement.offsetParent === null)) return;
    fetch('pikkumiesOpen.php?view=status', {credentials:'same-origin',cache:'no-store'})
        .then(function(response){return response.json();}).then(function(state){
            var pill = document.getElementById('pmss-pikkumies-pill');
            if (!pill) return;
            var label = <?= $pmssPikkumiesState['off'] ? "'Off'" : "(state.setup ? 'Needs setup' : state.ready ? 'Running' : 'Starting')" ?>;
            pill.textContent = label; pill.setAttribute('data-state', label);
            pill.className = 'pill ' + (label === 'Running' ? 'p-run' : label === 'Off' ? 'p-off' : 'p-warn');
        }).catch(function(){});
}
pmssPikkumiesStatusPoll(); setInterval(pmssPikkumiesStatusPoll, 5000);
<?php endif; ?>
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
    row.innerHTML = '<input class="label" maxlength="40" aria-label="Tab label" placeholder="Label"><input class="tooltip" maxlength="80" aria-label="Tab tooltip" placeholder="Tooltip"><input class="url" aria-label="Tab URL" placeholder="https://example.com/"><input class="position" inputmode="numeric" maxlength="3" size="4" aria-label="Tab position" title="Position: -99 to 99, higher sits further left. Empty keeps it among the other tabs." placeholder="Pos."><button type="button" onclick="this.parentNode.remove()">Remove</button>';
    container.appendChild(row);
}
function pmssConfigureFramesSave() {
    var rows = []; $('#configure-rows .configure-row').each(function(){rows.push({label:$(this).find('.label').val(),tooltip:$(this).find('.tooltip').val(),url:$(this).find('.url').val(),position:$(this).find('.position').val()});});
    pmssConfigureSend('customFrames',{rows:rows,password:document.getElementById('configure-tabs-password').value},'Save custom tabs? Their URLs will open from your panel.');
}
<?= pmssAppsCatalogSearchJs(true) ?>
</script></body></html>
