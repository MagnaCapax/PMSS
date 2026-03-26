#!/usr/bin/env php
<?php
/**
 * Inspect and apply per-user cgroup slice configuration.
 *
 * Canonical entry point for automation and operators. Supersedes the legacy
 * userCgroup.php name; a thin wrapper remains for backward compatibility.
 * Wrapper around PMSS\Cgroup\Manager logic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/cgroup/RealSystem.php';
require_once __DIR__.'/../lib/cgroup/Manager.php';

pmssRunCliEntrypoint(__FILE__, static function () use ($argv): int { return (new \PMSS\Cgroup\Manager(new \PMSS\Cgroup\RealSystem()))->run($argv); });
