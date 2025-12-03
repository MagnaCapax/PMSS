#!/usr/bin/php
<?php
/**
 * Inspect and apply per-user cgroup slice configuration.
 *
 * Canonical entry point for automation and operators. Supersedes the legacy
 * userCgroup.php name; a thin wrapper remains for backward compatibility.
 * Wrapper around PMSS\Cgroup\Manager logic.
 */

require_once __DIR__.'/../lib/cgroup/RealSystem.php';
require_once __DIR__.'/../lib/cgroup/Manager.php';

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $sys = new \PMSS\Cgroup\RealSystem();
    $mgr = new \PMSS\Cgroup\Manager($sys);
    exit($mgr->run($argv));
}