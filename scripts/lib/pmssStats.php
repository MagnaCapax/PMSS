<?php
/**
 * Per-account stats facade.
 *
 * Public function names remain in this facade while implementation is grouped
 * by context resolution and data collection.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['rtorrent/scgi.php', 'traffic.php', 'update.php', 'user/userConfigStore.php']);
require_once __DIR__.'/user/trafficLimit.php';
pmssRequireRelativeFiles(__DIR__, ['stats/context.php', 'stats/collect.php']);
