<?php
/**
 * Stable customer-side statistics facade; all helpers stay in the account tree.
 * Check the complete delivery before defining any API used by stats.php, so a
 * partial update retains the page's existing reload notice.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';
$pmssStatsModules = array('statsState', 'statsStatus', 'statsTraffic', 'statsResourceModel', 'statsResourceRender', 'statsCharts');
foreach ($pmssStatsModules as $pmssStatsModule) {
    $pmssStatsPath = __DIR__.'/'.$pmssStatsModule.'.php';
    if (!is_file($pmssStatsPath) || is_link($pmssStatsPath) || !is_readable($pmssStatsPath)) return;
}
foreach ($pmssStatsModules as $pmssStatsModule) require_once __DIR__.'/'.$pmssStatsModule.'.php';
unset($pmssStatsModules, $pmssStatsModule, $pmssStatsPath);
