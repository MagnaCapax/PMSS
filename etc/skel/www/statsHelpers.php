<?php
/**
 * Stable customer-side statistics facade; all helpers stay in the account tree.
 * Check the complete delivery before defining any API used by stats.php, so a
 * partial update retains the page's existing reload notice.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';
if (!function_exists('pmssWelcomeRequireLocalHelpers') || !pmssWelcomeRequireLocalHelpers(array(
    'statsState.php', 'statsStatus.php', 'statsTraffic.php', 'statsResourceModel.php', 'statsResourceRender.php', 'statsCharts.php',
))) return;
