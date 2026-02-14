#!/usr/bin/env php
<?php
/**
 * PMSS script: show Resources.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/resources/show.php';

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssShowResourcesMain($argv));
}
