<?php
/**
 * Stable customer-side statistics facade; all helpers stay in the account tree.
 * Check the complete delivery before defining any API used by stats.php, so a
 * partial update retains the page's existing reload notice.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';
$pmssStatsFiles = array(
    'statsState.php', 'statsStatus.php', 'statsTraffic.php', 'statsResourceModel.php', 'statsResourceRender.php', 'statsCharts.php',
);
if (function_exists('pmssWelcomeRequireLocalHelpers')) {
    if (!pmssWelcomeRequireLocalHelpers($pmssStatsFiles)) return;
} else {
    // Older delivered loaders only have the checked single-file API; preflight the whole group first.
    if (!function_exists('pmssWelcomeRequireLocalHelper')) return;
    foreach ($pmssStatsFiles as $pmssStatsFile) {
        $pmssStatsPath = __DIR__.'/'.$pmssStatsFile;
        if (!is_file($pmssStatsPath) || is_link($pmssStatsPath) || !is_readable($pmssStatsPath)) return;
    }
    foreach ($pmssStatsFiles as $pmssStatsFile) {
        if (!pmssWelcomeRequireLocalHelper($pmssStatsFile)) return;
    }
}
unset($pmssStatsFiles, $pmssStatsFile, $pmssStatsPath);
