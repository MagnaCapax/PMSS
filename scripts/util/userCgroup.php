#!/usr/bin/php
<?php
/**
 * Backward-compatibility wrapper.
 * Delegates to userConfigCgroup.php (canonical entry point).
 */

require_once __DIR__.'/userConfigCgroup.php';

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(pmssUserConfigCgroupMain($argv));
}
