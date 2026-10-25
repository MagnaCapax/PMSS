<?php
/** Shared customer version cards and their matching Configure section index. */
require_once __DIR__.'/configureTorrentClients.php';
require_once __DIR__.'/configureTorrentClientsOther.php';

/** Build the cards and index together so unavailable clients never leave empty anchors. */
function pmssConfigureTorrentClientCardsBuild($home, $path = '/var/lib/pmss/public/torrentClients.json')
{
    $labels = array('qbittorrent' => 'qBittorrent', 'deluge' => 'Deluge',
        'transmission' => 'Transmission', 'rtorrent' => 'rTorrent');
    $offered = $missing = $sections = array();
    $notInstalled = pmssConfigureTorrentClientNotInstalledRead($path);
    $controls = false;
    $html = '';
    $available = pmssConfigureTorrentClientVersionsAvailable($path);
    if ($available) foreach ($labels as $client => $label) {
        $versions = pmssConfigureTorrentClientVersionsOffered($client, $path);
        if ($versions) {
            $offered[$client] = $versions;
            $controls = pmssConfigureTorrentClientVersionControlsAvailable($home, $client, $versions) || $controls;
        } else $missing[] = $label;
    }
    // Missing projection is an update/availability state, not evidence that a client is absent.
    $message = $available ? '' : 'Version choice is not available on this server.';
    if ($missing) {
        $last = array_pop($missing);
        $message = ($missing ? implode(', ', $missing).' and ' : '').$last
            .($missing ? ' are' : ' is').' not installed on this server.';
    }
    // When there is nothing to switch, collect the running builds and notices in one card.
    if (!$controls) {
        $sentences = array();
        foreach ($offered as $client => $versions) {
            $status = pmssConfigureTorrentClientCardStatusRead($home, $client);
            $html .= '<p>'.pmssCustomerHtmlAttr($labels[$client].': '.$status['running']).'</p>';
            if ($status['notice'] !== '') $html .= '<p role="status">'.pmssCustomerHtmlAttr($status['notice']).'</p>';
            if ($notInstalled[$client] > 0) $sentences[] = pmssConfigureTorrentClientOtherVersionsMessage();
        }
        if ($message !== '') $sentences[] = $message;
        foreach (array_unique($sentences) as $sentence) $html .= '<p>'.pmssCustomerHtmlAttr($sentence).'</p>';
        return array(
            'sections' => array('torrent-client-versions' => 'Torrent client versions'),
            'html' => '<section class="configure-card" id="torrent-client-versions"><h2>Torrent client versions</h2>'
                .$html.'</section>',
        );
    }
    foreach ($offered as $client => $versions) {
        $sections[$client.'-version'] = $labels[$client].' version';
        $html .= pmssConfigureTorrentClientCardHtmlBuild($home, $client, $labels[$client], $versions, $message, $notInstalled[$client]);
        $message = ''; // List all absent clients once, at the end of the first card.
    }
    return array('sections' => $sections, 'html' => $html);
}

/** A sole current selection has no useful action, except recovery of an invalid link to system. */
function pmssConfigureTorrentClientVersionControlsAvailable($home, $client, $versions)
{
    if (count($versions) !== 1 || (string) key($versions) !== pmssConfigureTorrentClientSelectedVersion($home, $client)) return true;
    return isset($versions['system']) && is_link($home.'/bin/'.$client.'/default')
        && pmssTorrentClientResolve($home, $client) === null;
}

/** Use host availability rather than option count to explain missing builds. */
function pmssConfigureTorrentClientOtherVersionsMessage()
{
    return 'Other versions are not installed on this server. This depends on the server, not on your account.';
}

/** Share escaped-at-render status data between individual and combined cards. */
function pmssConfigureTorrentClientCardStatusRead($home, $client)
{
    return array('running' => $client === 'qbittorrent'
        ? pmssConfigureTorrentClientVersionLabel(pmssConfigureTorrentClientRunningVersion($home))
        : pmssConfigureTorrentClientOtherRunningVersion($home, $client),
        'notice' => trim(pmssConfigureFileRead($home.'/.config/pmss/torrentClients/'.$client.'.notice', 256)));
}

/** Keep the existing controls when a real selection change is possible. */
function pmssConfigureTorrentClientCardHtmlBuild($home, $client, $label, $versions, $message = '', $notInstalled = 0)
{
    $selectedVersion = pmssConfigureTorrentClientSelectedVersion($home, $client);
    $controls = pmssConfigureTorrentClientVersionControlsAvailable($home, $client, $versions);
    $status = pmssConfigureTorrentClientCardStatusRead($home, $client);
    $running = $status['running'];
    $notice = $status['notice'];
    $confirm = $client === 'transmission'
        ? 'Restart Transmission with this version? Its settings are not part of the configuration backup.'
        : 'Back up the current configuration and restart '.$label.' with this version?';
    ob_start();
    ?>
<section class="configure-card" id="<?= pmssCustomerHtmlAttr($client) ?>-version"><h2><?= pmssCustomerHtmlAttr($label) ?> version</h2><p>Running: <?= pmssCustomerHtmlAttr($running) ?></p><?php if ($notice !== ''): ?><p role="status"><?= pmssCustomerHtmlAttr($notice) ?></p><?php endif; ?>
<?php if ($controls): ?>
<div class="configure-actions"><select id="configure-<?= pmssCustomerHtmlAttr($client) ?>-version" aria-label="<?= pmssCustomerHtmlAttr($label) ?> version"><?php foreach ($versions as $version => $entry): ?><option value="<?= pmssCustomerHtmlAttr($version) ?>"<?= (string) $version === $selectedVersion ? ' selected' : '' ?>><?= pmssCustomerHtmlAttr(pmssConfigureTorrentClientVersionLabel($version)) ?></option><?php endforeach; ?></select>
<input type="password" id="configure-<?= pmssCustomerHtmlAttr($client) ?>-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend('torrentClientSwitch',{client:'<?= pmssCustomerHtmlAttr($client) ?>',version:document.getElementById('configure-<?= pmssCustomerHtmlAttr($client) ?>-version').value,password:document.getElementById('configure-<?= pmssCustomerHtmlAttr($client) ?>-password').value},'<?= pmssCustomerHtmlAttr($confirm) ?>')">Switch version</button></div><?php endif; ?><?php if ($notInstalled > 0): ?>
<p><?= pmssCustomerHtmlAttr(pmssConfigureTorrentClientOtherVersionsMessage()) ?></p><?php endif; ?><?php if ($message !== ''): ?>
<p><?= pmssCustomerHtmlAttr($message) ?></p><?php endif; ?></section>
<?php
    return ob_get_clean();
}
