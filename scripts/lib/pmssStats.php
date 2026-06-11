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
pmssRequireRelativeFiles(__DIR__, ['rtorrent/scgi.php', 'traffic.php', 'update.php', 'user/userConfigStore.php']);
require_once __DIR__.'/user/trafficLimit.php';
pmssRequireRelativeFiles(__DIR__, ['cli/optionParser.php', 'stats/context.php', 'stats/collect.php', 'stats/render.php']);
