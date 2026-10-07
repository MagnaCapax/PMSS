<?php
/** Read-only customer app catalog. All dependencies are delivered in www/. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/userMediaStackPanel.php';

/** Return the only destinations used by links on this page. */
function pmssAppsAllowedUrlsRead(): array
{
    $wiki = 'https://wiki.pulsedmedia.com/index.php/';
    $urls = array(
        'welcome' => 'index.php',
        'stylesheet' => 'screen.css',
        'docker' => $wiki.'Rootless_DOCKER',
        'lighttpd' => $wiki.'Lighttpd_Custom_Configuration',
        'selfHosted' => $wiki.'Self-Hosted_Apps_on_PMSS',
        'mediaStack' => $wiki.'Install_Media_Stack',
    );
    foreach (array_keys(pmssAppsCatalogRead()) as $category) {
        $urls[$category] = $urls['selfHosted'].'#'.str_replace(' ', '_', $category);
    }
    return $urls;
}

/** The closed wiki catalog: each entry is a name and native-install flag. */
function pmssAppsCatalogRead(): array
{
    return array(
        'Media servers and live TV' => array(array('Tunarr', true), array('Channels DVR', true), array('Threadfin', true), array('Koel', true), array('mStream', true)),
        'Comics, books and audiobooks' => array(array('Komga', true), array('Ubooquity', true), array('Calibre', true), array('Calibre-Web Automated', false), array('BookLore', false), array('Grimmory', false), array('Storyteller', false)),
        'Downloads and requests' => array(array('qui', true), array('JDownloader 2', true), array('slskd', true), array('Seerr', true), array('DroppedNeedle', false), array('ReadMeABook', false)),
        'Library automation' => array(array('Scryer', true), array('Medusa', true), array('SickGear', true), array('Headphones', true), array('Mylar3', true), array('Bindery', true), array('Profilarr', false)),
        'Library maintenance and transcoding' => array(array('Maintainerr', false), array('Cleanuparr', true), array('Deleterr', true), array('Tdarr', true), array('Unmanic', true), array('tinyMediaManager', true)),
        'Photos, files and passwords' => array(array('Immich', false), array('PhotoPrism', true), array('Copyparty', true), array('Vaultwarden', false)),
        'Dashboards, chat and desktop' => array(array('Organizr', true), array('The Lounge', true), array('Mattermost', true), array('Webtop', false)),
    );
}

/** Build installed-app rows from the existing customer-side catalogs. */
function pmssAppsInstalledRowsBuild(): array
{
    $rows = array();
    foreach (pmssMediaStackPanelAppDefinitionsRead() as $definition) {
        $rows[] = array($definition['label'], 'Install Media Stack button');
    }
    $rows[] = array('Cloudplow', 'Install Media Stack button');
    foreach (array_keys(pmssCustomerManagedAppDefinitions()) as $name) {
        $rows[] = array($name, 'start or stop toggle');
    }
    $rows[] = array('rTorrent and ruTorrent', 'always on');
    return $rows;
}

/** Escape text and closed URLs in the same way throughout the page. */
function pmssAppsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Build one wiki link from the closed destination map. */
function pmssAppsWikiLinkBuild($url, $label): string
{
    return '<a href="'.pmssAppsEscape($url).'" target="_blank" rel="noopener">'.pmssAppsEscape($label).'</a>';
}

$pmssAppsUrls = pmssAppsAllowedUrlsRead();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Seedbox apps</title>
  <link href="<?= pmssAppsEscape($pmssAppsUrls['stylesheet']) ?>" rel="stylesheet" media="screen" />
  <style>
    .pmss-apps-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .pmss-apps-table th, .pmss-apps-table td { padding: 8px; border: 1px solid #ddd; text-align: left; }
    .pmss-apps-table th { background: #f2f2f2; }
  </style>
</head>
<body>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body">
<h1>Apps</h1>
<p>Applications you can use on this seedbox. Native means it runs directly in your account; Docker means you run it in a container with rootless Docker.</p>
<h2>Installed by PMSS</h2>
<table class="pmss-apps-table"><thead><tr><th>Application</th><th>Native</th><th>Docker</th></tr></thead><tbody>
<?php foreach (pmssAppsInstalledRowsBuild() as $row): ?>
<tr><td><?= pmssAppsEscape($row[0]) ?></td><td><?php if ($row[1] === 'always on'): ?>always on<?php else: ?><a href="<?= pmssAppsEscape($pmssAppsUrls['welcome']) ?>" target="_top">Welcome tab</a> — <?= pmssAppsEscape($row[1]) ?><?php endif; ?></td><td><?= pmssAppsWikiLinkBuild($pmssAppsUrls['docker'], 'Rootless DOCKER') ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<h2>Self-hosted apps you can run</h2>
<table class="pmss-apps-table"><thead><tr><th>Application</th><th>Category</th><th>Native</th><th>Docker</th></tr></thead><tbody>
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?>
<tr><td><?= pmssAppsEscape($app[0]) ?></td><td><?= pmssAppsEscape($category) ?></td><td><?= $app[1] ? pmssAppsWikiLinkBuild($pmssAppsUrls[$category], 'Without Docker') : 'Docker only' ?></td><td><?= pmssAppsWikiLinkBuild($pmssAppsUrls[$category], 'With Docker') ?></td></tr>
<?php endforeach; endforeach; ?>
</tbody></table>
<p>Each application's own login is what protects it: other accounts on the same server can reach a port bound to 127.0.0.1, so turn the login on before you open it up.</p>
<p><?= pmssAppsWikiLinkBuild($pmssAppsUrls['docker'], 'Rootless DOCKER') ?> · <?= pmssAppsWikiLinkBuild($pmssAppsUrls['lighttpd'], 'Lighttpd Custom Configuration') ?> · <?= pmssAppsWikiLinkBuild($pmssAppsUrls['selfHosted'], 'Self-Hosted Apps on PMSS') ?> · <?= pmssAppsWikiLinkBuild($pmssAppsUrls['mediaStack'], 'Install Media Stack') ?></p>
</div><div class="full_bottom"></div></div></div>
</body>
</html>
