#!/usr/bin/env php
<?php
/**
 * Operator-side compatibility entrypoint for the customer-delivered installer.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once '/etc/skel/bin/dockerInstallLsio.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssDockerInstallLsioMain');
