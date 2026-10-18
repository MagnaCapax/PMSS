<?php
/** Customer app controls and the closed, documentation-only Docker catalog. */
require_once __DIR__.'/scriptsInc.php';
if (isset($_GET['status']) || isset($_GET['log'])) {
    $helper = isset($_GET['status']) ? 'pmssCustomerAppsStatusJsonEmit' : 'pmssCustomerAppsLogJsonEmit';
    if (!function_exists($helper)) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(array('updating' => true, 'message' => 'The Apps page is being updated on this server. Reload it in a few minutes.'));
        return;
    }
    $helper();
    return;
}
require_once __DIR__.'/userMediaStackPanel.php';

require_once __DIR__.'/appsRuntime.php';
if (is_file(__DIR__.'/configure.php') && is_file(__DIR__.'/configureSettings.php')) {
    header('Location: configure.php', true, 302);
    return;
}

if (!function_exists('pmssAppsRuntimeReady') || !pmssAppsRuntimeReady()) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Apps</title></head><body><p>The Apps page is being updated on this server. Reload it in a few minutes.</p></body></html>';
    return;
}
list($home, $username, $hostname) = pmssCustomerAccountContext();
$status = pmssCustomerAppsStatusRead($home, $username, $hostname);
$urls = pmssAppsAllowedUrlsRead();
$lsioCatalog = is_file(__DIR__.'/appsLsioCatalog.php') ? require __DIR__.'/appsLsioCatalog.php' : array();
if (!is_array($lsioCatalog)) $lsioCatalog = array();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Apps</title>
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
</style></head><body><div id="pmss-action-notice" role="status" aria-live="polite"></div>
<div id="wrap"><div id="full_page"><div class="full_top_nohd"></div><div class="full_body"><div class="apps">
<h1>Apps</h1>
<?= pmssCustomerAppsSectionHtmlBuild($status, 'apps.php?status=1') ?>
<h2>Find more apps</h2><p class="note">You can set up more apps yourself with rootless Docker. LinuxServer.io examples publish ports on every address (for example 8080:8080). On this server that puts the app on the internet: publish it as 127.0.0.1:8080:8080 instead, and turn on the app's own login — other accounts on this server can reach a port on 127.0.0.1.</p>
<input id="pmss-apps-search" class="search" type="search" aria-label="Search apps" placeholder="Search apps, e.g. comics, photos, IRC">
<h2>Guides on the Pulsed Media wiki</h2><div class="list" id="pmss-more-apps">
<?php foreach (pmssAppsCatalogRead() as $category => $apps): foreach ($apps as $app): ?><div class="mrow" data-category="<?= pmssCustomerHtmlAttr($category) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app[0]) ?><?php if ($app[3] !== ''): ?><span class="tag"><?= pmssCustomerHtmlAttr($app[3]) ?></span><?php endif; ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app[2]) ?></span><a href="<?= pmssCustomerHtmlAttr($urls[$category]) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div><?php endforeach; endforeach; ?></div>
<?php if ($lsioCatalog): ?><h2>More from LinuxServer.io (<?= count($lsioCatalog) ?>)</h2><div id="pmss-lsio-apps">
<?php $lastCategory = null; foreach ($lsioCatalog as $app): if ($app['category'] !== $lastCategory): if ($lastCategory !== null): ?></div></div><?php endif; $lastCategory = $app['category']; ?><div class="lsio-group"><div class="group"><?= pmssCustomerHtmlAttr(str_replace(',', ', ', $lastCategory)) ?></div><div class="list"><?php endif; ?>
<div class="mrow" data-category="<?= pmssCustomerHtmlAttr(str_replace(',', ', ', $app['category'])) ?>" data-search="<?= pmssCustomerHtmlAttr($app['name']) ?>"><span class="name"><?= pmssCustomerHtmlAttr($app['title']) ?></span><span class="desc"><?= pmssCustomerHtmlAttr($app['description']) ?></span><a href="<?= pmssCustomerHtmlAttr($app['guide']) ?>" target="_blank" rel="noopener">Setup guide &#8599;</a></div>
<?php endforeach; ?></div></div></div><?php endif; ?>
</div></div><div class="full_bottom"></div></div></div>
<script>
$('#pmss-apps-search').on('input', function() {
    var query = this.value.toLowerCase().trim(), compactQuery = query.replace(/[\s-]/g, '');
    $('#pmss-more-apps .mrow, #pmss-lsio-apps .mrow').each(function() {
        var row = $(this), search = (row.find('.name, .desc').text() + ' ' + row.attr('data-category') + ' ' + (row.attr('data-search') || '')).toLowerCase();
        row.toggle(search.indexOf(query) !== -1 || search.replace(/[\s-]/g, '').indexOf(compactQuery) !== -1);
    });
    $('#pmss-lsio-apps .lsio-group').each(function() { $(this).toggle($(this).find('.mrow:visible').length > 0); });
});
</script></body></html>
