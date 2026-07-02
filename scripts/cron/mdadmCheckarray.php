#!/usr/bin/env php
<?php
/**
 * Cron entrypoint for the PMSS mdadm redundancy check guard.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/mdadmCheckarray.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssMdadmCheckarrayMain');
