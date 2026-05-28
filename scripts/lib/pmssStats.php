<?php
/**
 * Per-account terminal stats facade.
 *
 * Public function names remain in this facade while implementation is grouped
 * by context resolution, data collection, and rendering/CLI handling.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/rtorrent/scgi.php';
require_once __DIR__.'/traffic.php';
require_once __DIR__.'/update.php';
require_once __DIR__.'/user/userConfigStore.php';
require_once __DIR__.'/user/trafficLimit.php';
require_once __DIR__.'/cli/optionParser.php';

require_once __DIR__.'/stats/context.php';
require_once __DIR__.'/stats/collect.php';
require_once __DIR__.'/stats/render.php';
