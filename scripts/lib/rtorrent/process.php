<?php
/**
 * rTorrent process facade for watchdog and maintenance callers.
 *
 * @license Proprietary
 */

require_once __DIR__.'/../user/watchdog.php';
require_once __DIR__.'/recovery.php';
require_once __DIR__.'/scgi.php';
require_once __DIR__.'/watchdogState.php';
require_once __DIR__.'/processInspection.php';
require_once __DIR__.'/processLifecycle.php';

// Signal constants for systems without pcntl.
if (!defined('SIGTERM')) define('SIGTERM', 15);
if (!defined('SIGKILL')) define('SIGKILL', 9);
